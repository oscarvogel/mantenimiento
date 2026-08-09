<?php

declare(strict_types=1);

namespace App\Application\MaintenanceCircuit;

use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\Port\PreventiveOrderFromNoticePort;
use DomainException;

final readonly class GeneratePreventiveOrderFromNotice
{
    public function __construct(private PreventiveOrderFromNoticePort $orders)
    {
    }

    public function execute(ActorContext $actor, int $noticeId, ?int $responsibleUserId = null): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La orden preventiva requiere una cuenta perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('ordenes.editar')) {
            throw new DomainException('No tenés permiso para generar órdenes de trabajo.');
        }
        if ($noticeId <= 0) {
            throw new DomainException('El aviso preventivo no es válido.');
        }
        $responsibleUserId ??= $actor->userId();
        if ($responsibleUserId <= 0) {
            throw new DomainException('El responsable de la orden no es válido.');
        }

        return $this->orders->generate(
            $actor->companyId(),
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
            $noticeId,
            $responsibleUserId,
            $actor->userId(),
        );
    }
}
