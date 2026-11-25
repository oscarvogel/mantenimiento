<?php

declare(strict_types=1);

namespace App\Application\Neumaticos\Port;

/**
 * Confirma que la sucursal existe, está activa y es de la empresa. La
 * sucursal pertenece a Organización; Neumáticos solo la referencia por ID.
 */
interface NeumaticBranchScope
{
    public function isActiveInCompany(int $companyId, int $branchId): bool;
}
