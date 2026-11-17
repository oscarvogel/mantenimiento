<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts\Port;

use App\Domain\CustomerAccounts\BillableLeg;

/**
 * Contrato de lectura con Viajes: el tramo que se formaliza, bloqueado para la
 * transacción. Respeta empresa y sucursales del actor (`null` = todas).
 */
interface BillableLegLookup
{
    /** @param list<int>|null $branchIds */
    public function findForUpdate(int $companyId, ?array $branchIds, int $legId): ?BillableLeg;
}
