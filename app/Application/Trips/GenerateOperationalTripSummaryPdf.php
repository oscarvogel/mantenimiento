<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripSummaryPdfRenderer;
use App\Domain\Trips\Trip;
use DomainException;

final class GenerateOperationalTripSummaryPdf
{
    public function __construct(
        private readonly GetOperationalTrip $getTrip,
        private readonly OperationalTripSummaryPdfRenderer $renderer,
    ) {
    }

    public function execute(ActorContext $actor, int $tripId): string
    {
        $trip = $this->getTrip->execute($actor, $tripId);
        if (($trip['trip']['estado'] ?? null) !== Trip::FINALIZADO) {
            throw new DomainException('El resumen PDF solo se puede descargar cuando el viaje está finalizado.');
        }

        return $this->renderer->render($trip);
    }
}
