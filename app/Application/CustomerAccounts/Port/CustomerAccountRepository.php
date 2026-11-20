<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts\Port;

use App\Domain\CustomerAccounts\CustomerCharge;
use App\Domain\CustomerAccounts\NonCollectibleClosure;
use DateTimeImmutable;

interface CustomerAccountRepository
{
    public function customerExists(int $companyId, int $customerId): bool;

    /** ¿Hay un cargo vigente o un cierre no cobrable vigente con esta clave? */
    public function hasActiveCharge(int $companyId, string $activeKey): bool;

    public function hasActiveNonCollectible(int $companyId, string $activeKey): bool;

    /** Guarda el cargo y su asiento en el libro ARS. Devuelve el id del cargo. */
    public function addCharge(int $companyId, CustomerCharge $charge, int $userId, DateTimeImmutable $now): int;

    /**
     * Cargo vigente bloqueado para anularlo.
     *
     * @return array{id:int,cliente_id:int,sentido:string,importe_ars:float,documento_fecha:string}|null
     */
    public function findActiveChargeForUpdate(int $companyId, int $chargeId): ?array;

    /** Marca el cargo anulado, libera su clave y asienta el contraasiento. */
    public function annulCharge(int $companyId, int $chargeId, string $reason, int $userId, DateTimeImmutable $now): void;

    public function addNonCollectible(int $companyId, NonCollectibleClosure $closure, int $userId, DateTimeImmutable $now): int;

    public function reopenNonCollectible(int $companyId, int $legId, string $reason, int $userId, DateTimeImmutable $now): bool;
}
