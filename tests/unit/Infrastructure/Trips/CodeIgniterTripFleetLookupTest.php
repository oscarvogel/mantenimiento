<?php

declare(strict_types=1);

use App\Application\Assets\Port\EquipmentFleetLookup;
use App\Application\Employees\Port\EmployeeDirectoryLookup;
use App\Infrastructure\Trips\CodeIgniterTripFleetLookup;
use PHPUnit\Framework\TestCase;

final class CodeIgniterTripFleetLookupTest extends TestCase
{
    public function testMapeaElEquipoQueResuelveElContextoDeActivos(): void
    {
        $lookup = new CodeIgniterTripFleetLookup(
            new class implements EquipmentFleetLookup {
                public function equipment(int $companyId, int $equipmentId): ?array
                {
                    return $companyId === 7 && $equipmentId === 11
                        ? [
                            'id' => 11,
                            'branchId' => 3,
                            'label' => 'CC789DD · SEM-01',
                            'plate' => 'CC789DD',
                            'code' => 'SEM-01',
                            'tractor' => false,
                            'trailer' => true,
                        ]
                        : null;
                }

                public function equipmentByPlate(int $companyId, string $plate): ?array
                {
                    return null;
                }

                public function activeTrailers(int $companyId, array $equipmentIds): array
                {
                    return [];
                }
            },
            new class implements EmployeeDirectoryLookup {
                public function driver(int $companyId, int $employeeId): ?array
                {
                    return null;
                }
            },
        );

        $equipment = $lookup->equipment(7, 11);

        self::assertNotNull($equipment);
        self::assertSame(11, $equipment['id']);
        self::assertSame(3, $equipment['branchId']);
        self::assertSame('CC789DD · SEM-01', $equipment['label']);
        self::assertTrue($equipment['trailer']);
        self::assertFalse($equipment['tractor']);
    }

    public function testUnEquipoInexistenteDevuelveNull(): void
    {
        $lookup = new CodeIgniterTripFleetLookup(
            new class implements EquipmentFleetLookup {
                public function equipment(int $companyId, int $equipmentId): ?array
                {
                    return null;
                }

                public function equipmentByPlate(int $companyId, string $plate): ?array
                {
                    return null;
                }

                public function activeTrailers(int $companyId, array $equipmentIds): array
                {
                    return [];
                }
            },
            new class implements EmployeeDirectoryLookup {
                public function driver(int $companyId, int $employeeId): ?array
                {
                    return null;
                }
            },
        );

        self::assertNull($lookup->equipment(7, 11));
    }

    public function testElChoferVieneDelContextoDeEmpleados(): void
    {
        $lookup = new CodeIgniterTripFleetLookup(
            new class implements EquipmentFleetLookup {
                public function equipment(int $companyId, int $equipmentId): ?array
                {
                    return null;
                }

                public function equipmentByPlate(int $companyId, string $plate): ?array
                {
                    return null;
                }

                public function activeTrailers(int $companyId, array $equipmentIds): array
                {
                    return [];
                }
            },
            new class implements EmployeeDirectoryLookup {
                public function driver(int $companyId, int $employeeId): ?array
                {
                    return $companyId === 7 && $employeeId === 20
                        ? ['id' => 20, 'label' => 'Carlos Secre', 'active' => true]
                        : null;
                }
            },
        );

        $driver = $lookup->driver(7, 20);

        self::assertNotNull($driver);
        self::assertSame(20, $driver['id']);
        self::assertSame('Carlos Secre', $driver['label']);
        self::assertTrue($driver['active']);
    }
}
