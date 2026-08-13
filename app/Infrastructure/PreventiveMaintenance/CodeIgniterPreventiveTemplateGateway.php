<?php

declare(strict_types=1);

namespace App\Infrastructure\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\PreventiveTemplateGateway;
use App\Domain\PreventiveMaintenance\PlantillaPreventiva;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class CodeIgniterPreventiveTemplateGateway implements PreventiveTemplateGateway
{
    private BaseConnection $database;

    public function __construct(?BaseConnection $database = null)
    {
        $this->database = $database ?? Database::connect();
    }

    public function listActiveCandidates(int $companyId): array
    {
        if (! $this->database->tableExists('plantillas_mantenimiento')
            || ! $this->database->tableExists('plantilla_mantenimiento_items')) {
            return [];
        }

        $rows = $this->database->table('plantilla_mantenimiento_items i')
            ->select('i.*, p.nombre plantilla_nombre, p.tipo_equipo_id, p.marca, p.modelo, ts.nombre servicio_nombre')
            ->join('plantillas_mantenimiento p', 'p.id = i.plantilla_id', 'inner')
            ->join('tipos_servicio ts', 'ts.id = i.tipo_servicio_id', 'inner')
            ->groupStart()
                ->where('p.empresa_id', $companyId)
                ->orGroupStart()
                    ->where('p.empresa_id', null)
                    ->where('p.ambito', 'GLOBAL')
                ->groupEnd()
            ->groupEnd()
            ->where('p.activo', 1)
            ->where('p.deleted_at', null)
            ->where('i.activo', 1)
            ->where('ts.activo', 1)
            ->orderBy('p.id', 'ASC')
            ->orderBy('i.id', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $row): PlantillaPreventiva => new PlantillaPreventiva(
            (int) $row['id'],
            (int) $row['plantilla_id'],
            (string) $row['plantilla_nombre'],
            (int) $row['tipo_servicio_id'],
            (string) $row['servicio_nombre'],
            $row['tipo_equipo_id'] === null ? null : (int) $row['tipo_equipo_id'],
            self::nullableString($row['marca']),
            self::nullableString($row['modelo']),
            $row['intervalo_km'] === null ? null : (int) $row['intervalo_km'],
            DecimalHours::toTenths($row['intervalo_horas']),
            $row['intervalo_dias'] === null ? null : (int) $row['intervalo_dias'],
            $row['intervalo_km'] === null ? null : ($row['anticipacion_km'] === null ? 0 : (int) $row['anticipacion_km']),
            $row['intervalo_horas'] === null ? null : (DecimalHours::toTenths($row['anticipacion_horas']) ?? 0),
            $row['intervalo_dias'] === null ? null : ($row['anticipacion_dias'] === null ? 0 : (int) $row['anticipacion_dias']),
            (string) $row['prioridad'],
            self::nullableString($row['observaciones']),
        ), $rows);
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
