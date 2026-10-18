<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

use App\Application\Trips\TripDashboardFilters;

interface TripDashboardReadModel
{
    /**
     * @param list<int>|null $branchIds null significa todas las sucursales de la empresa
     *
     * @return list<array{
     *     id:int,
     *     sucursal_id:int,
     *     dominio:string,
     *     numero_viaje:int,
     *     fecha_salida:?string,
     *     fecha_regreso:?string,
     *     km_salida:?int,
     *     km_regreso:?int,
     *     litros:float,
     *     cargas:int
     * }>
     */
    public function trips(int $companyId, ?array $branchIds, TripDashboardFilters $filters): array;

    /** @param list<int>|null $branchIds @return list<array{id:int,code:string,name:string}> */
    public function branches(int $companyId, ?array $branchIds): array;

    /** @param list<int>|null $branchIds @return list<string> */
    public function domains(int $companyId, ?array $branchIds): array;
}
