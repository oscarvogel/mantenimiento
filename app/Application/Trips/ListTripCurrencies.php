<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\PendingFuelPriceRepository;
use DomainException;

/**
 * Monedas con las que se puede cargar combustible y su cotización.
 *
 * Los viajes cruzan fronteras: una carga puede venir en dólares o en reales.
 * La cotización no se inventa: sale de la tabla de cotizaciones de la empresa
 * y, si falta, el operador la carga.
 */
final readonly class ListTripCurrencies
{
    public function __construct(private PendingFuelPriceRepository $fuel)
    {
    }

    /** @return list<array{code:string, label:string, rate:?float}> */
    public function execute(ActorContext $actor): array
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para cargar combustible de viajes.');
        }

        return $this->fuel->currencies($companyId);
    }
}
