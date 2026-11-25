<?php

declare(strict_types=1);

namespace App\Domain\Neumaticos;

use DateTimeImmutable;
use DomainException;

/**
 * Un movimiento de depósito (`deposito_movimientos`): entrada o salida por
 * planilla, o ajuste con motivo. Explica cada cambio de existencia.
 */
final readonly class NeumaticDepositMovement
{
    public const ENTRY = 'ENTRADA';
    public const EXIT = 'SALIDA';
    public const ADJUSTMENT = 'AJUSTE';

    public string $sheetNumber;
    public string $notes;

    public function __construct(
        public string $type,
        public int $depositId,
        public int $neumaticId,
        public int $quantity,
        public DateTimeImmutable $at,
        string $sheetNumber,
        string $notes = '',
    ) {
        if (! in_array($type, [self::ENTRY, self::EXIT, self::ADJUSTMENT], true)) {
            throw new DomainException('El tipo de movimiento de depósito no es válido.');
        }
        $this->sheetNumber = trim($sheetNumber);
        $this->notes = trim($notes);
    }

    /**
     * Aplica el movimiento a la existencia con la regla de su tipo.
     */
    public function applyTo(NeumaticDepositStock $stock): void
    {
        if ($stock->depositId() !== $this->depositId || $stock->neumaticId() !== $this->neumaticId) {
            throw new DomainException('El movimiento no corresponde a esta existencia.');
        }

        match ($this->type) {
            self::ENTRY => $stock->receive($this->quantity, $this->sheetNumber),
            self::EXIT => $stock->dispatch($this->quantity, $this->sheetNumber),
            self::ADJUSTMENT => $stock->adjust($this->quantity, $this->notes),
        };
    }
}
