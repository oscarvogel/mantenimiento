<?php

declare(strict_types=1);

namespace App\Domain\Trips;

use DateTimeImmutable;
use DomainException;

final readonly class TripLogbookEntry
{
    public const CARGA = 'CARGA';
    public const FRONTERA = 'FRONTERA';
    public const CLIENTE = 'CLIENTE';

    private const TYPES = [self::CARGA, self::FRONTERA, self::CLIENTE];

    public function __construct(
        private int $order,
        private string $type,
        private string $location,
        private DateTimeImmutable $arrivedAt,
        private ?DateTimeImmutable $departedAt,
        private int $freeHours,
        private int $employeeId,
        private int $conceptId,
        private ?float $adjustedDays = null,
        private ?string $adjustmentReason = null,
        private ?string $notes = null,
    ) {
        if ($order <= 0) {
            throw new DomainException('El orden del diario de bordo debe empezar en 1.');
        }
        if (! in_array($type, self::TYPES, true)) {
            throw new DomainException('El tipo de etapa del diario no es válido.');
        }
        if (trim($location) === '') {
            throw new DomainException('Cada etapa del diario necesita una ubicación.');
        }
        if ($employeeId <= 0) {
            throw new DomainException('El chofer de la estadía no es válido.');
        }
        if ($conceptId <= 0) {
            throw new DomainException('El concepto de la estadía no es válido.');
        }
        if ($freeHours < 0 || $freeHours > 720) {
            throw new DomainException('La franquicia debe estar entre 0 y 720 horas.');
        }
        if ($departedAt !== null && $this->date($departedAt) < $this->date($arrivedAt)) {
            throw new DomainException('La salida de la etapa no puede ser anterior a la llegada.');
        }
        if ($adjustedDays !== null && $adjustedDays < 0) {
            throw new DomainException('Los días a liquidar no pueden ser negativos.');
        }
        if ($this->requiresAdjustmentReason()) {
            throw new DomainException('Indicá el motivo de la corrección de días.');
        }
    }

    public function order(): int
    {
        return $this->order;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function location(): string
    {
        return trim($this->location);
    }

    public function arrivedAt(): DateTimeImmutable
    {
        return $this->arrivedAt;
    }

    public function departedAt(): ?DateTimeImmutable
    {
        return $this->departedAt;
    }

    public function freeHours(): int
    {
        return $this->freeHours;
    }

    public function employeeId(): int
    {
        return $this->employeeId;
    }

    public function conceptId(): int
    {
        return $this->conceptId;
    }

    public function adjustedDays(): ?float
    {
        return $this->adjustedDays;
    }

    public function adjustmentReason(): ?string
    {
        return $this->adjustmentReason === null ? null : trim($this->adjustmentReason);
    }

    public function notes(): ?string
    {
        return $this->notes === null ? null : trim($this->notes);
    }

    public function presenceDays(): ?int
    {
        if ($this->departedAt === null) {
            return null;
        }

        return (int) $this->date($this->arrivedAt)->diff($this->date($this->departedAt))->format('%a');
    }

    public function calculatedDays(): ?float
    {
        $presenceDays = $this->presenceDays();
        if ($presenceDays === null) {
            return null;
        }

        return round(max(0.0, $presenceDays - ($this->freeHours / 24)), 2);
    }

    public function liquidatedDays(): ?float
    {
        return $this->adjustedDays ?? $this->calculatedDays();
    }

    public function hasAdjustment(): bool
    {
        return $this->adjustedDays !== null
            && $this->calculatedDays() !== null
            && abs($this->adjustedDays - $this->calculatedDays()) > 0.0001;
    }

    private function requiresAdjustmentReason(): bool
    {
        if ($this->adjustedDays === null) {
            return false;
        }
        if ($this->calculatedDays() === null) {
            return trim((string) $this->adjustmentReason) === '';
        }

        return $this->hasAdjustment() && trim((string) $this->adjustmentReason) === '';
    }

    private function date(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTime(0, 0, 0);
    }
}
