<?php

declare(strict_types=1);

namespace App\Application\Employees;

final readonly class DriverAssignmentImportRow
{
    public function __construct(
        public string $sheet,
        public int $rowNumber,
        public string $brand,
        public string $plate,
        public string $normalizedPlate,
        public ?string $driverName,
    ) {}
}
