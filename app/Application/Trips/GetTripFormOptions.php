<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\TripFormReadModel;
use DomainException;

final class GetTripFormOptions
{
    public function __construct(private readonly TripFormReadModel $readModel)
    {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para planificar viajes.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('Tu usuario no tiene sucursales autorizadas para planificar viajes.');
        }

        $options = $this->readModel->options($actor->companyId(), $branchIds);
        $options['links'] = [
            'index' => base_url('viajes/operativos'),
            'store' => base_url('viajes/operativos'),
        ];
        $options['stopTypes'] = [
            ['value' => 'CARGA', 'label' => 'Carga'],
            ['value' => 'DESCARGA', 'label' => 'Descarga'],
            ['value' => 'HITO', 'label' => 'Hito operativo'],
        ];

        return $options;
    }
}
