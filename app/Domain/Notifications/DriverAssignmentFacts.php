<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use InvalidArgumentException;

/**
 * Hechos mínimos para decidir si un chofer puede recibir un mensaje de una
 * unidad. Los hidrata el adaptador de infraestructura; la decisión es pura.
 */
final readonly class DriverAssignmentFacts
{
    public function __construct(
        public string $startsOn,
        public ?string $endsOn,
        public bool $employeeActive,
        public bool $employeeDeleted,
        public string $equipmentState,
        public bool $equipmentDeleted,
        public bool $typeControlsKilometers,
        public bool $companyActive,
        public bool $companyDeleted,
        public bool $companyWhatsAppEnabled,
    ) {
        foreach ([$this->startsOn, $this->endsOn] as $date) {
            if ($date === null) {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new InvalidArgumentException('Las fechas de la asignación deben tener formato Y-m-d.');
            }
        }
    }
}
