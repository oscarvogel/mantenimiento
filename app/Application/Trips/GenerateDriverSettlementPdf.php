<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\DriverSettlementPdfRenderer;
use App\Domain\Trips\Trip;
use DomainException;

final class GenerateDriverSettlementPdf
{
    public function __construct(
        private readonly GetOperationalTrip $getTrip,
        private readonly DriverSettlementPdfRenderer $renderer,
    ) {
    }

    public function execute(ActorContext $actor, int $tripId): string
    {
        $trip = $this->getTrip->execute($actor, $tripId);
        if (($trip['trip']['estado'] ?? null) !== Trip::FINALIZADO) {
            throw new DomainException('La liquidación para el chofer se puede emitir cuando el viaje está finalizado.');
        }
        if (($trip['settlement'] ?? null) === null) {
            throw new DomainException('No se puede emitir la liquidación: falta un chofer responsable con porcentaje definido.');
        }

        return $this->renderer->render($trip);
    }
}
