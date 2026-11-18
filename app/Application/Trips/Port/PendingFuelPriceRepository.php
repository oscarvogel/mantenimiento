<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

use App\Domain\Trips\TripMoney;

/**
 * Cargas de combustible registradas sin precio, que se completan después.
 */
interface PendingFuelPriceRepository
{
    /**
     * Cargas activas sin precio de viajes no cancelados, de la más vieja a la
     * más nueva.
     *
     * @param list<int>|null $branchIds
     * @return list<array{
     *     id:int, viaje_id:int, viaje_numero:int, viaje_estado:string, sucursal:?string,
     *     fecha:string, litros:float, kilometraje:?int, estacion:?string,
     *     comprobante:?string, observaciones:?string, tractor:?string
     * }>
     */
    public function pending(int $companyId, ?array $branchIds): array;

    /**
     * Bloquea la carga para completarla. Null si no existe, está quitada o no
     * es del alcance del usuario.
     *
     * @param list<int>|null $branchIds
     * @return array{id:int, viaje_id:int, viaje_numero:int, viaje_estado:string, con_precio:bool}|null
     */
    public function findForUpdate(int $companyId, ?array $branchIds, int $fuelId): ?array;

    /** Completa el precio sólo si la carga todavía no lo tenía. */
    public function completePrice(int $companyId, int $fuelId, TripMoney $money, int $actorUserId): bool;

    /** @return list<array{code:string,label:string,rate:?float}> */
    public function currencies(int $companyId): array;
}
