<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts\Port;

interface CustomerAccountReadModel
{
    /**
     * Tramos de viajes finalizados con su estado comercial: PENDIENTE,
     * SIN_CLIENTE, FORMALIZADO o NO_COBRABLE.
     *
     * @param list<int>|null $branchIds
     * @return array{rows:list<array<string,mixed>>,counts:array<string,int>}
     */
    public function commercialClosingQueue(int $companyId, ?array $branchIds, string $status, ?string $search): array;

    /**
     * Clientes con cuenta corriente y su saldo por moneda (#588).
     *
     * El saldo de cada moneda sale del libro: los importes originales se
     * derivan de los movimientos y el consolidado en pesos, de la diferencia
     * entre Debe y Haber.
     *
     * @return array{rows:list<array<string,mixed>>,totals:array<string,float>,pagination:array<string,mixed>}
     */
    public function customerBalances(int $companyId, ?string $search, ?string $currency, int $page, int $perPage): array;

    /**
     * @return array<string,mixed>|null Cliente, movimientos con saldo acumulado y cargos.
     */
    public function customerStatement(int $companyId, int $customerId): ?array;

    /** @return list<array{id:int,nombre:string}> */
    public function customerOptions(int $companyId): array;
}
