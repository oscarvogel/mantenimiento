<?php

declare(strict_types=1);

namespace App\Domain\Neumaticos;

use DomainException;

/**
 * Existencia de un neumático en un depósito (`deposito_stock`).
 *
 * Invariante 5 del plan: el stock no queda negativo salvo por un ajuste
 * documentado. Las entradas y salidas comunes van por planilla; el ajuste
 * exige motivo y es el único camino a un saldo negativo, que así queda
 * explicado en lugar de ocultarse.
 */
final class NeumaticDepositStock
{
    private function __construct(
        private readonly int $depositId,
        private readonly int $neumaticId,
        private int $quantity,
    ) {
        if ($depositId <= 0) {
            throw new DomainException('Indicá el depósito.');
        }
        if ($neumaticId <= 0) {
            throw new DomainException('Indicá el neumático.');
        }
    }

    public static function empty(int $depositId, int $neumaticId): self
    {
        return new self($depositId, $neumaticId, 0);
    }

    public static function reconstitute(int $depositId, int $neumaticId, int $quantity): self
    {
        return new self($depositId, $neumaticId, $quantity);
    }

    public function receive(int $quantity, string $sheetNumber): void
    {
        $this->assertMovement($quantity, $sheetNumber);
        $this->quantity += $quantity;
    }

    public function dispatch(int $quantity, string $sheetNumber): void
    {
        $this->assertMovement($quantity, $sheetNumber);
        if ($this->quantity - $quantity < 0) {
            throw new DomainException(sprintf(
                'No hay existencia suficiente en el depósito: hay %d y se quieren sacar %d.',
                $this->quantity,
                $quantity,
            ));
        }
        $this->quantity -= $quantity;
    }

    /**
     * Corrige la existencia por diferencia de conteo. Puede dejarla negativa:
     * el motivo es lo que la documenta.
     */
    public function adjust(int $delta, string $reason): void
    {
        if ($delta === 0) {
            throw new DomainException('El ajuste no cambia la existencia.');
        }
        if (trim($reason) === '') {
            throw new DomainException('Un ajuste de existencia necesita motivo.');
        }
        $this->quantity += $delta;
    }

    public function depositId(): int
    {
        return $this->depositId;
    }

    public function neumaticId(): int
    {
        return $this->neumaticId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    private function assertMovement(int $quantity, string $sheetNumber): void
    {
        if ($quantity <= 0) {
            throw new DomainException('La cantidad del movimiento tiene que ser mayor a cero.');
        }
        if (trim($sheetNumber) === '') {
            throw new DomainException('Indicá el número de planilla del movimiento.');
        }
    }
}
