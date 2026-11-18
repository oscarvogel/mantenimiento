<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\PendingFuelPriceRepository;

final class ListPendingFuelPrices
{
    public function __construct(private readonly PendingFuelPriceRepository $fuel)
    {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor): array
    {
        $companyId = CompleteFuelPrices::company($actor);
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        $loads = $branchIds === [] ? [] : $this->fuel->pending($companyId, $branchIds);

        $liters = 0.0;
        $trips = [];
        foreach ($loads as $load) {
            $liters += $load['litros'];
            $trips[$load['viaje_id']] = true;
        }

        return [
            'loads' => array_map(static fn (array $load): array => $load + [
                'url' => base_url('viajes/operativos/' . $load['viaje_id']),
            ], $loads),
            'summary' => ['loads' => count($loads), 'trips' => count($trips), 'liters' => round($liters, 2)],
            'currencies' => $this->fuel->currencies($companyId),
            'links' => [
                'save' => base_url('viajes/operativos/combustible-sin-precio'),
                'trips' => base_url('viajes/operativos'),
            ],
        ];
    }
}
