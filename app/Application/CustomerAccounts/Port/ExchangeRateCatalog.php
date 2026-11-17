<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts\Port;

/** Monedas habilitadas y cotización vigente de la empresa, solo como sugerencia. */
interface ExchangeRateCatalog
{
    public function currencyExists(string $currency): bool;

    public function currentRate(int $companyId, string $currency): ?float;

    /** @return list<string> ARS y las monedas activas del maestro. */
    public function currencies(): array;
}
