<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\TripLocalityLookup;
use DomainException;

final class ListTripLocalityProvinces
{
    public function __construct(private readonly TripLocalityLookup $localities)
    {
    }

    /** @return list<array{id:int,label:string}> */
    public function execute(ActorContext $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para consultar provincias de viajes.');
        }

        return $this->localities->provinces();
    }
}
