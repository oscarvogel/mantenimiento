<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\PendingFuelPriceRepository;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripMoney;
use DomainException;

/**
 * Completa el precio de cargas de combustible registradas sin importe. Se
 * permite también en viajes finalizados: sólo se agrega el dato que faltaba,
 * no se toca nada más del viaje. Todo o nada: si una carga no se puede
 * completar, no se guarda ninguna.
 */
final class CompleteFuelPrices
{
    public function __construct(
        private readonly PendingFuelPriceRepository $fuel,
        private readonly TripDirectoryLookup $directory,
        private readonly TripsUnitOfWork $unitOfWork,
    ) {
    }

    /** @param list<FuelPriceEntry> $entries */
    public function execute(ActorContext $actor, array $entries): int
    {
        $companyId = self::company($actor);
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($entries === []) {
            throw new DomainException('Cargá el importe de al menos una carga de combustible.');
        }
        if ($branchIds === []) {
            throw new DomainException('La carga de combustible no existe o no está autorizada.');
        }

        $seen = [];
        foreach ($entries as $entry) {
            if (isset($seen[$entry->fuelId])) {
                throw new DomainException('La misma carga de combustible vino dos veces.');
            }
            $seen[$entry->fuelId] = true;
            if (! $this->directory->currencyExists($entry->currency)) {
                throw new DomainException('La moneda elegida no está disponible.');
            }
        }

        return $this->unitOfWork->transactional(function () use ($actor, $entries, $companyId, $branchIds): int {
            foreach ($entries as $entry) {
                $load = $this->fuel->findForUpdate($companyId, $branchIds, $entry->fuelId);
                if ($load === null) {
                    throw new DomainException('Una de las cargas de combustible ya no existe o no está autorizada. Recargá la pantalla.');
                }
                if ($load['viaje_estado'] === Trip::CANCELADO) {
                    throw new DomainException(sprintf('El viaje #%d está cancelado: su combustible no se puede completar.', $load['viaje_numero']));
                }
                if ($load['con_precio']) {
                    throw new DomainException(sprintf('Una carga del viaje #%d ya tiene precio. Recargá la pantalla para ver lo pendiente.', $load['viaje_numero']));
                }

                $money = new TripMoney($entry->amount, $entry->currency, $entry->exchangeRate);
                if ($money->amount() <= 0) {
                    throw new DomainException(sprintf('El importe de la carga del viaje #%d debe ser mayor que cero.', $load['viaje_numero']));
                }
                if (! $this->fuel->completePrice($companyId, $entry->fuelId, $money, $actor->userId())) {
                    throw new DomainException(sprintf('Una carga del viaje #%d ya tiene precio. Recargá la pantalla para ver lo pendiente.', $load['viaje_numero']));
                }
            }

            return count($entries);
        });
    }

    public static function company(ActorContext $actor): int
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('viajes.operar')) {
            throw new DomainException('No tenés permiso para completar precios de combustible.');
        }

        return $companyId;
    }
}
