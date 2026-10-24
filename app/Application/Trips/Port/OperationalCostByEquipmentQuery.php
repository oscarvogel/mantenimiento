<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

use DateTimeImmutable;

/**
 * Costo operativo de una unidad en un periodo: combustible, gastos de viaje y
 * liquidacion del chofer, con el costo por kilometraje.
 *
 * Lo publica Viajes, que es dueno de esos datos. Lo consumen los reportes de
 * costo y el circuito de ordenes de trabajo, para poder mostrar el costo total
 * de operacion de una unidad.
 *
 * No es costo de mantenimiento: no entra en el `costo_total` de una orden de
 * trabajo. Son dos costos distintos y se informan por separado.
 */
interface OperationalCostByEquipmentQuery
{
    /**
     * @return array{
     *     fuel:float,
     *     expenses:float,
     *     driverSettlement:float,
     *     total:float,
     *     kilometers:float,
     *     costPerKilometer:?float,
     *     trips:int
     * }|null Null cuando la unidad no tiene viajes en el periodo.
     */
    public function byEquipment(
        int $companyId,
        int $equipmentId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): ?array;
}
