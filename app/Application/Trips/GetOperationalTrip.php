<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripReadModel;
use App\Domain\Trips\Trip;
use DomainException;

final class GetOperationalTrip
{
    public function __construct(private readonly OperationalTripReadModel $readModel)
    {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, int $tripId): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('viajes.ver')) {
            throw new DomainException('No tenés permiso para consultar viajes.');
        }
        if ($tripId <= 0) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }

        $detail = $this->readModel->find($actor->companyId(), $branchIds, $tripId);
        if ($detail === null) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }

        $detail['links'] = ['detail' => base_url('viajes/operativos/' . $tripId)];
        $detail['canOperate'] = $actor->hasPermission('viajes.operar');
        $detail['canStart'] = $detail['canOperate'] && ($detail['trip']['estado'] ?? null) === Trip::PLANIFICADO;
        $detail['canCancel'] = $detail['canOperate'] && ($detail['trip']['estado'] ?? null) === Trip::PLANIFICADO;

        return $detail;
    }
}
