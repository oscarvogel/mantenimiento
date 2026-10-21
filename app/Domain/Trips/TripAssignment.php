<?php

declare(strict_types=1);

namespace App\Domain\Trips;

use DateTimeImmutable;
use DomainException;

final readonly class TripAssignment
{
    public const TRACTOR = 'TRACTOR';
    public const ACOPLADO = 'ACOPLADO';
    public const CHOFER = 'CHOFER';

    private const ROLES = [self::TRACTOR, self::ACOPLADO, self::CHOFER];

    public function __construct(
        private string $role,
        private int $referenceId,
        private DateTimeImmutable $from,
        private ?DateTimeImmutable $to = null,
        private ?string $reason = null,
        private ?float $liquidationPercentage = null,
    ) {
        if (! in_array($role, self::ROLES, true)) {
            throw new DomainException('El rol de la asignación no es válido.');
        }
        if ($referenceId <= 0) {
            throw new DomainException('La referencia de la asignación no es válida.');
        }
        if ($to !== null && $to < $from) {
            throw new DomainException('El fin de la asignación no puede ser anterior a su inicio.');
        }
        if ($liquidationPercentage !== null && ($liquidationPercentage < 0 || $liquidationPercentage > 100)) {
            throw new DomainException('El porcentaje de liquidación debe estar entre 0 y 100.');
        }
    }

    public function role(): string
    {
        return $this->role;
    }

    public function referenceId(): int
    {
        return $this->referenceId;
    }

    public function from(): DateTimeImmutable
    {
        return $this->from;
    }

    public function to(): ?DateTimeImmutable
    {
        return $this->to;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function liquidationPercentage(): ?float
    {
        return $this->liquidationPercentage;
    }

    public function isEquipment(): bool
    {
        return $this->role !== self::CHOFER;
    }
}
