<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Assets\Port\EquipmentFleetLookup;
use App\Application\Employees\Port\EmployeeDirectoryLookup;
use App\Application\Trips\Port\TripFleetLookup;

/**
 * El circuito de viajes no conoce el esquema de `equipos` ni el de `empleados`:
 * pregunta a los contextos que son duenos de esas tablas.
 */
final class CodeIgniterTripFleetLookup implements TripFleetLookup
{
    public function __construct(
        private readonly EquipmentFleetLookup $equipment,
        private readonly EmployeeDirectoryLookup $employees,
    ) {
    }

    public function equipment(int $companyId, int $equipmentId): ?array
    {
        $item = $this->equipment->equipment($companyId, $equipmentId);
        if ($item === null) {
            return null;
        }

        return [
            'id' => $item['id'],
            'branchId' => $item['branchId'],
            'label' => $item['label'],
            'trailer' => $item['trailer'],
            'tractor' => $item['tractor'],
        ];
    }

    public function driver(int $companyId, int $employeeId): ?array
    {
        return $this->employees->driver($companyId, $employeeId);
    }
}
