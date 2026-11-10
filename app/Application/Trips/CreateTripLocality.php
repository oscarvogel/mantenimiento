<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\TripLocalityCreator;
use App\Application\Trips\Port\TripLocalityLookup;
use App\Domain\Geography\InvalidLocalityInput;
use App\Domain\Geography\LocalityName;
use DomainException;

final class CreateTripLocality
{
    public function __construct(
        private readonly TripLocalityCreator $creator,
        private readonly TripLocalityLookup $localities,
    ) {
    }

    /** @return array{id:int,name:string,label:string,created:bool} */
    public function execute(ActorContext $actor, int $provinceId, string $name): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para dar de alta localidades de viajes.');
        }
        if ($provinceId <= 0) {
            throw new InvalidLocalityInput('Elegí la provincia de la localidad.');
        }

        $localityName = LocalityName::fromString($name);
        $result = $this->creator->findOrCreate($provinceId, $localityName->value());
        $locality = $this->localities->find($result['id']);
        if ($locality === null) {
            throw new DomainException('La localidad se guardó pero no se pudo recuperar para seleccionarla.');
        }

        return [
            'id' => $locality['id'],
            'name' => $locality['name'],
            'label' => $locality['label'],
            'created' => $result['created'],
        ];
    }
}
