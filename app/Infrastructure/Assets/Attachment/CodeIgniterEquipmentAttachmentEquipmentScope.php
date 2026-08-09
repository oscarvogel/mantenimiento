<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets\Attachment;

use App\Application\Assets\Attachment\Port\EquipmentAttachmentEquipmentScope;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterEquipmentAttachmentEquipmentScope implements EquipmentAttachmentEquipmentScope
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function currentBranchId(
        int $companyId,
        int $equipmentId,
        ?array $authorizedBranchIds,
    ): ?int {
        if ($authorizedBranchIds === []) {
            return null;
        }

        $builder = $this->database->table('equipos')
            ->select('sucursal_id')
            ->where('empresa_id', $companyId)
            ->where('id', $equipmentId)
            ->where('estado', 'ACTIVO')
            ->where('deleted_at', null);
        if ($authorizedBranchIds !== null) {
            $builder->whereIn('sucursal_id', $authorizedBranchIds);
        }
        $row = $builder->get()->getRowArray();

        return $row === null ? null : (int) $row['sucursal_id'];
    }
}
