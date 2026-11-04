<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

interface OperationalTripDashboardReadModel
{
    /** @param list<int>|null $branchIds @return list<array{id:int,code:string,name:string}> */
    public function branches(int $companyId, ?array $branchIds): array;

    /**
     * Viajes operativos sin filtro de fecha. El caso de uso recorta el período
     * para los indicadores, pero conserva los viajes en curso y planificados.
     *
     * @param list<int>|null $branchIds
     * @return list<array{
     *     id:int,
     *     numero:int,
     *     estado:string,
     *     sucursal_id:int,
     *     fecha_salida_planificada:string,
     *     fecha_regreso_planificada:string,
     *     fecha_salida_real:?string,
     *     fecha_regreso_real:?string,
     *     km_salida:?int,
     *     km_regreso:?int,
     *     litros:float,
     *     combustible_ars:float,
     *     gastos_ars:float,
     *     flete_facturacion_ars:float
     * }>
     */
    public function trips(int $companyId, ?array $branchIds, ?int $branchId): array;

    /**
     * @param list<int> $tripIds
     * @return array<int,array{tractor:?string,choferes:list<string>,origen:?string,destino:?string,paradas:int}>
     */
    public function details(int $companyId, array $tripIds): array;
}
