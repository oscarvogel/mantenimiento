<?php

declare(strict_types=1);

namespace App\Application\Neumaticos\Port;

/**
 * Confirma que la sucursal existe, está activa y es de la empresa, y lista las
 * sucursales que el actor puede elegir. La sucursal pertenece a Organización;
 * Neumáticos solo la referencia por ID y no escribe en ella.
 */
interface NeumaticBranchScope
{
    public function isActiveInCompany(int $companyId, int $branchId): bool;

    /**
     * @param list<int>|null $branchIds null = todas las sucursales de la empresa
     * @return list<array{id: int, name: string}>
     */
    public function activeBranches(int $companyId, ?array $branchIds): array;
}
