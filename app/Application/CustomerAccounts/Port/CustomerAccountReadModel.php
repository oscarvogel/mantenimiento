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

    /** @return list<array<string,mixed>> Clientes con movimientos o con tramos formalizados, con Debe, Haber y saldo ARS. */
    public function customerBalances(int $companyId, ?string $search): array;

    /** @return array<string,mixed>|null Cliente, movimientos con saldo acumulado y cargos. */
    public function customerStatement(int $companyId, int $customerId): ?array;

    /** @return list<array{id:int,nombre:string}> */
    public function customerOptions(int $companyId): array;
}
