<?php

declare(strict_types=1);

namespace App\Application\Neumaticos\Port;

use App\Domain\Neumaticos\NeumaticDepositMovement;
use App\Domain\Neumaticos\NeumaticDepositStock;

interface NeumaticDepositRepository
{
    /** @param list<int>|null $branchIds null = todas las sucursales de la empresa */
    public function isActiveDeposit(int $companyId, ?array $branchIds, int $depositId): bool;

    /**
     * Existencia actual con bloqueo. Null si el neumático nunca pasó por ese
     * depósito.
     */
    public function stockForUpdate(int $depositId, int $neumaticId): ?NeumaticDepositStock;

    /**
     * Guarda la existencia y su movimiento en la misma transacción: no hay
     * saldo sin movimiento que lo explique.
     */
    public function record(NeumaticDepositStock $stock, NeumaticDepositMovement $movement, int $actorUserId): void;
}
