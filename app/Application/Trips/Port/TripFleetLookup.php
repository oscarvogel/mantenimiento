<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

interface TripFleetLookup
{
    /** @return array{id:int,branchId:int,label:string,trailer:bool,tractor:bool}|null */
    public function equipment(int $companyId, int $equipmentId): ?array;

    /** @return array{id:int,label:string,active:bool}|null */
    public function driver(int $companyId, int $employeeId): ?array;
}
