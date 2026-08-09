<?php

declare(strict_types=1);

namespace App\Application\Assets;

use App\Application\Assets\Port\EquipmentQrReadModel;
use App\Application\Identity\ActorContext;
use DomainException;

final class GetEquipmentQrPayload
{
    public function __construct(private readonly EquipmentQrReadModel $readModel) {}

    public function execute(ActorContext $actor, int $equipmentId): EquipmentQrPayload
    {
        if ($equipmentId <= 0 || $actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('equipos.ver')) {
            throw new DomainException('No tenés permiso para obtener el acceso QR del equipo.');
        }
        $row = $this->readModel->findScoped(
            $actor->companyId(),
            $equipmentId,
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
        );
        if ($row === null) {
            throw new DomainException('El equipo no existe o no está autorizado para el actor.');
        }

        return new EquipmentQrPayload((int) $row['id'], (string) $row['codigo'], '/mantenimiento/equipos/' . $row['id']);
    }
}
