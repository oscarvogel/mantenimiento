<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\DriverSettlementPdfRenderer;
use DomainException;

/**
 * PDF de la liquidación del chofer.
 *
 * Imprime la misma proyección segura que la vista en pantalla: el renderer
 * nunca recibe el detalle administrativo del viaje. Antes del cierre sale
 * marcado como borrador.
 */
final class GenerateDriverSettlementPdf
{
    public function __construct(
        private readonly GetDriverSettlement $getSettlement,
        private readonly DriverSettlementPdfRenderer $renderer,
    ) {
    }

    public function execute(ActorContext $actor, int $tripId): string
    {
        $statement = $this->getSettlement->execute($actor, $tripId);
        if (! ($statement['available'] ?? false)) {
            throw new DomainException('No se puede emitir la liquidación: ' . lcfirst((string) ($statement['pending'] ?? 'faltan datos del chofer.')));
        }

        return $this->renderer->render($statement);
    }
}
