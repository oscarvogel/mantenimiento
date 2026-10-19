<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DateTimeImmutable;

final readonly class TripConflict
{
    public function __construct(
        public int $tripId,
        public int $tripNumber,
        public string $status,
        public string $role,
        public int $referenceId,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {
    }

    public function describe(): string
    {
        $role = match ($this->role) {
            'TRACTOR' => 'El tractor/camión',
            'ACOPLADO' => 'El acoplado',
            'CHOFER' => 'El chofer',
            default => 'La asignación',
        };

        return sprintf(
            '%s ya está asignado al viaje #%d (%s) entre %s y %s.',
            $role,
            $this->tripNumber,
            $this->status,
            $this->from->format('d/m/Y H:i'),
            $this->to->format('d/m/Y H:i'),
        );
    }
}
