<?php

declare(strict_types=1);

namespace App\Domain\Trips;

use DateTimeImmutable;
use DomainException;

final readonly class TripCashMovement
{
    public const ANTICIPO = 'ANTICIPO';
    public const RETIRO = 'RETIRO';
    public const DEVOLUCION = 'DEVOLUCION';

    private const TYPES = [self::ANTICIPO, self::RETIRO, self::DEVOLUCION];

    public function __construct(
        private string $type,
        private DateTimeImmutable $date,
        private TripMoney $money,
        private ?string $notes = null,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new DomainException('El tipo de movimiento de caja no es válido.');
        }
        if ($money->amount() <= 0) {
            throw new DomainException('El importe del movimiento de caja debe ser mayor que cero.');
        }
    }

    public function type(): string
    {
        return $this->type;
    }

    public function date(): DateTimeImmutable
    {
        return $this->date;
    }

    public function money(): TripMoney
    {
        return $this->money;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function isDelivery(): bool
    {
        return $this->type !== self::DEVOLUCION;
    }
}
