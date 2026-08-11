<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\Port\PreventiveLibraryReferenceGateway;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterPreventiveLibraryReferenceGateway implements PreventiveLibraryReferenceGateway
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function serviceByCode(string $code): ?array
    {
        return $this->database->table('tipos_servicio')
            ->where('codigo', $this->code($code))
            ->get()->getRowArray() ?: null;
    }

    public function taskByCode(string $code): ?array
    {
        return $this->database->table('tareas_mantenimiento')
            ->where('codigo', $this->code($code))
            ->get()->getRowArray() ?: null;
    }

    public function materialByCodes(string $serviceCode, string $itemCode): ?array
    {
        if (! $this->database->tableExists('tipo_servicio_materiales')) {
            return null;
        }

        return $this->database->table('tipo_servicio_materiales m')
            ->select('m.*')
            ->join('tipos_servicio s', 's.id = m.tipo_servicio_id', 'inner')
            ->where('s.codigo', $this->code($serviceCode))
            ->where('m.codigo', $this->code($itemCode))
            ->get()->getRowArray() ?: null;
    }

    public function activeEquipmentTypeByName(string $name): ?array
    {
        foreach ($this->database->table('tipos_equipo')->select('id, nombre')->where('activo', 1)->get()->getResultArray() as $row) {
            if (mb_strtolower(trim((string) $row['nombre'])) === mb_strtolower(trim($name))) {
                return ['id' => (int) $row['id'], 'nombre' => (string) $row['nombre']];
            }
        }

        return null;
    }

    public function companyTemplateByCode(int $companyId, string $code): ?array
    {
        if (! $this->database->tableExists('plantillas_mantenimiento')) {
            return null;
        }

        return $this->database->table('plantillas_mantenimiento')
            ->where('empresa_id', $companyId)
            ->where('codigo', $this->code($code))
            ->where('deleted_at', null)
            ->get()->getRowArray() ?: null;
    }

    public function templateItemByCodes(int $companyId, string $templateCode, string $serviceCode): ?array
    {
        if (! $this->database->tableExists('plantilla_mantenimiento_items')) {
            return null;
        }

        return $this->database->table('plantilla_mantenimiento_items i')
            ->select('i.*')
            ->join('plantillas_mantenimiento p', 'p.id = i.plantilla_id', 'inner')
            ->join('tipos_servicio s', 's.id = i.tipo_servicio_id', 'inner')
            ->where('p.empresa_id', $companyId)
            ->where('p.codigo', $this->code($templateCode))
            ->where('p.deleted_at', null)
            ->where('s.codigo', $this->code($serviceCode))
            ->get()->getRowArray() ?: null;
    }

    private function code(string $value): string
    {
        return mb_strtoupper(trim($value));
    }
}
