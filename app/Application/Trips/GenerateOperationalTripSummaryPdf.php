<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripSummaryPdfRenderer;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripKpis;
use Closure;
use DomainException;

/**
 * Resumen imprimible del viaje: KPI del viaje y el detalle completo.
 *
 * Con el viaje En curso se imprime igual, marcado como borrador: los datos
 * pueden cambiar hasta que se cierre (pedido operativo, 20/11/2026). Un viaje
 * Planificado o Cancelado no tiene recorrido que resumir.
 */
final class GenerateOperationalTripSummaryPdf
{
    private readonly Closure $today;

    public function __construct(
        private readonly GetOperationalTrip $getTrip,
        private readonly OperationalTripSummaryPdfRenderer $renderer,
        ?Closure $today = null,
    ) {
        $this->today = $today ?? static fn (): string => date('Y-m-d');
    }

    public function execute(ActorContext $actor, int $tripId): string
    {
        $trip = $this->getTrip->execute($actor, $tripId);
        $status = $trip['trip']['estado'] ?? null;
        if (! in_array($status, [Trip::EN_CURSO, Trip::FINALIZADO], true)) {
            throw new DomainException('El resumen PDF se puede descargar cuando el viaje está en curso o finalizado.');
        }

        $today = ($this->today)();
        $totals = $trip['totals'] ?? [];
        $tons = null;
        foreach ($trip['legs'] ?? [] as $leg) {
            if (($leg['toneladas'] ?? null) !== null) {
                $tons = ($tons ?? 0.0) + (float) $leg['toneladas'];
            }
        }
        $km = $trip['consumption']['km'] ?? null;

        $trip['summary'] = [
            'draft' => $status === Trip::EN_CURSO,
            'generated_on' => $today,
            'consumption_source' => $trip['consumption']['source'] ?? null,
            'kpis' => TripKpis::calculate(
                $km === null ? null : (int) $km,
                (float) ($totals['fuel_liters'] ?? 0),
                (float) ($totals['fuel_amount_ars'] ?? 0),
                ($totals['invoice_ars'] ?? null) === null ? null : (float) $totals['invoice_ars'],
                ($totals['operating_cost_ars'] ?? null) === null ? null : (float) $totals['operating_cost_ars'],
                ($totals['margin_ars'] ?? null) === null ? null : (float) $totals['margin_ars'],
                $tons,
                TripKpis::days(
                    $trip['trip']['fecha_salida_real'] ?? null,
                    $trip['trip']['fecha_regreso_real'] ?? null,
                    $today,
                ),
            ),
        ];

        return $this->renderer->render($trip);
    }
}
