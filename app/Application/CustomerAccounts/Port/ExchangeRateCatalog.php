<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts\Port;

/**
 * Monedas habilitadas y cotización vigente de la empresa.
 *
 * La cotización vigente es solo una sugerencia y la fuente de la valorización
 * actual: el valor histórico de cada operación queda congelado en el cargo, en
 * el cobro o en la imputación, y nunca se recalcula.
 */
interface ExchangeRateCatalog
{
    public function currencyExists(string $currency): bool;

    public function currentRate(int $companyId, string $currency): ?float;

    /**
     * Cotización vigente con su fecha y su fuente, para poder mostrar de dónde
     * sale la valorización actual.
     *
     * @return array{rate:float,fecha:string|null,fuente:string}|null
     */
    public function currentRateDetail(int $companyId, string $currency): ?array;

    /** @return list<string> ARS y las monedas activas del maestro. */
    public function currencies(): array;
}
