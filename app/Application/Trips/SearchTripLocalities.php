<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\TripLocalityLookup;
use DomainException;

final class SearchTripLocalities
{
    public function __construct(private readonly TripLocalityLookup $localities)
    {
    }

    /** @return list<array{id:int,name:string,label:string}> */
    public function execute(ActorContext $actor, string $query): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para consultar localidades de viajes.');
        }

        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        return $this->localities->search($query, 30);
    }
}
