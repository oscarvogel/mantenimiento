<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use DateTimeImmutable;

/**
 * Entrada, salida o ajuste de depósito (`RegistrarEntradaDepositoCommand`,
 * `RegistrarSalidaDepositoCommand`). Un solo comando porque los tres
 * comparten scope, transacción y registro del movimiento; la regla de cada
 * tipo vive en el dominio.
 *
 * En un ajuste, `quantity` es la diferencia con signo y `notes` el motivo.
 */
final readonly class RegisterDepositMovementCommand
{
    public function __construct(
        public string $type,
        public int $depositId,
        public int $neumaticId,
        public int $quantity,
        public string $sheetNumber = '',
        public string $notes = '',
        public ?DateTimeImmutable $at = null,
    ) {
    }
}
