<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets\Attachment;

use App\Application\Assets\Attachment\EquipmentAttachmentPage;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentReadModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterEquipmentAttachmentReadModel implements EquipmentAttachmentReadModel
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function forEquipment(
        int $companyId,
        int $equipmentId,
        ?array $authorizedBranchIds,
        int $page,
        int $perPage,
    ): EquipmentAttachmentPage {
        if ($authorizedBranchIds === []) {
            return new EquipmentAttachmentPage([], 0, $page, $perPage);
        }

        $total = $this->scopedBuilder($companyId, $equipmentId, $authorizedBranchIds)->countAllResults();
        $items = $this->scopedBuilder($companyId, $equipmentId, $authorizedBranchIds)
            ->select([
                'a.id', 'a.equipo_id', 'a.sucursal_snapshot_id', 's.nombre sucursal_snapshot_nombre',
                'a.tipo', 'a.nombre_original', 'a.mime_type', 'a.tamanio', 'a.descripcion',
                'a.created_by', 'uc.nombre created_by_nombre', 'a.created_at', 'a.retirado_at',
                'a.retirado_by', 'ur.nombre retirado_by_nombre', 'a.motivo_retiro',
            ])
            ->join('sucursales s', 's.id = a.sucursal_snapshot_id', 'inner')
            ->join('usuarios uc', 'uc.id = a.created_by', 'inner')
            ->join('usuarios ur', 'ur.id = a.retirado_by', 'left')
            ->orderBy('a.created_at', 'DESC')
            ->orderBy('a.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return new EquipmentAttachmentPage($items, $total, $page, $perPage);
    }

    /** @param list<int>|null $authorizedBranchIds */
    private function scopedBuilder(int $companyId, int $equipmentId, ?array $authorizedBranchIds): BaseBuilder
    {
        $builder = $this->database->table('equipo_adjuntos a')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id', 'inner')
            ->where('a.empresa_id', $companyId)
            ->where('a.equipo_id', $equipmentId)
            ->where('e.deleted_at', null);
        if ($authorizedBranchIds !== null) {
            $builder->whereIn('e.sucursal_id', $authorizedBranchIds);
        }

        return $builder;
    }
}
