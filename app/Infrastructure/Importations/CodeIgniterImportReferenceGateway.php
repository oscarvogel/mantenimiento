<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\Port\ImportReferenceGateway;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterImportReferenceGateway implements ImportReferenceGateway
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function activeBranchByCode(int $companyId, string $code): ?array
    {
        $row = $this->database->table('sucursales')->select('id, codigo')
            ->where('empresa_id', $companyId)->where('codigo', mb_strtoupper(trim($code)))
            ->where('estado', 1)->where('deleted_at', null)->get()->getRowArray();
        return $row === null ? null : ['id' => (int) $row['id'], 'codigo' => (string) $row['codigo']];
    }

    public function activeEquipmentTypeByName(string $name): ?array
    {
        foreach ($this->database->table('tipos_equipo')->select('id, nombre, controla_km, controla_horas')->where('activo', 1)->get()->getResultArray() as $row) {
            if (mb_strtolower(trim((string) $row['nombre'])) === mb_strtolower(trim($name))) {
                return ['id' => (int) $row['id'], 'nombre' => (string) $row['nombre'], 'controla_km' => (bool) $row['controla_km'], 'controla_horas' => (bool) $row['controla_horas']];
            }
        }
        return null;
    }

    public function activeBrandByName(int $companyId, string $name): ?array
    {
        foreach ($this->database->table('marcas')->select('id, nombre')->where('empresa_id', $companyId)->where('activo', 1)->get()->getResultArray() as $row) {
            if (mb_strtolower(trim((string) $row['nombre'])) === mb_strtolower(trim($name))) {
                return ['id' => (int) $row['id'], 'nombre' => (string) $row['nombre']];
            }
        }
        return null;
    }

    public function activeModelByName(int $companyId, int $brandId, int $typeId, string $name): ?array
    {
        $rows = $this->database->table('modelos')->select('id, nombre')->where('empresa_id', $companyId)
            ->where('marca_id', $brandId)->where('tipo_equipo_id', $typeId)->where('activo', 1)->get()->getResultArray();
        foreach ($rows as $row) {
            if (mb_strtolower(trim((string) $row['nombre'])) === mb_strtolower(trim($name))) {
                return ['id' => (int) $row['id'], 'nombre' => (string) $row['nombre']];
            }
        }
        return null;
    }

    public function activeEquipmentByCode(int $companyId, string $code): ?array
    {
        $row = $this->database->table('equipos e')
            ->select('e.id, e.sucursal_id, e.km_actual, e.horas_actuales, t.controla_km, t.controla_horas')
            ->join('tipos_equipo t', 't.id = e.tipo_equipo_id')
            ->where('e.empresa_id', $companyId)->where('e.codigo', mb_strtoupper(trim($code)))
            ->where('e.estado', 'ACTIVO')->where('e.deleted_at', null)->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        return [
            'id' => (int) $row['id'], 'sucursal_id' => (int) $row['sucursal_id'],
            'controla_km' => (bool) $row['controla_km'], 'controla_horas' => (bool) $row['controla_horas'],
            'km_actual' => $row['km_actual'] === null ? null : (int) $row['km_actual'],
            'horas_actuales' => $row['horas_actuales'] === null ? null : (string) $row['horas_actuales'],
        ];
    }

    public function equipmentCodeExists(int $companyId, string $code): bool
    {
        return $this->database->table('equipos')->where('empresa_id', $companyId)
            ->where('codigo', mb_strtoupper(trim($code)))->where('deleted_at', null)->countAllResults() > 0;
    }

    public function equipmentPlateExists(int $companyId, string $plate): bool
    {
        return $this->database->table('equipos')->where('empresa_id', $companyId)
            ->where('patente', mb_strtoupper(trim($plate)))->where('deleted_at', null)->countAllResults() > 0;
    }

    public function readingDuplicateExists(int $companyId, int $equipmentId, string $recordedAt, ?int $kilometers, ?string $hours, string $origin): bool
    {
        $builder = $this->database->table('lecturas_equipo')->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)->where('fecha_lectura', $recordedAt)
            ->where('origen', $origin)->where('anulada', 0);
        $kilometers === null ? $builder->where('kilometraje', null) : $builder->where('kilometraje', $kilometers);
        $hours === null ? $builder->where('horometro', null) : $builder->where('horometro', $hours);
        return $builder->countAllResults() > 0;
    }
}
