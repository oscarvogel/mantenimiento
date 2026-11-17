<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\CustomerAccounts\Port\ExchangeRateCatalog;
use App\Domain\CustomerAccounts\FrozenExchangeRate;
use DateTimeImmutable;
use DomainException;

/** Arma la cotización congelada: la informada, o la vigente del catálogo de la empresa. */
final class RateResolver
{
    public static function resolve(
        ExchangeRateCatalog $rates,
        int $companyId,
        string $currency,
        ?float $informedRate,
        DateTimeImmutable $date,
    ): FrozenExchangeRate {
        $currency = strtoupper(trim($currency));
        if (! $rates->currencyExists($currency)) {
            throw new DomainException('La moneda elegida no está disponible.');
        }
        if ($currency === 'ARS') {
            return new FrozenExchangeRate('ARS', 1.0, $date, FrozenExchangeRate::SOURCE_PESOS);
        }
        if ($informedRate !== null) {
            return new FrozenExchangeRate($currency, $informedRate, $date, FrozenExchangeRate::SOURCE_MANUAL);
        }

        $current = $rates->currentRate($companyId, $currency);
        if ($current === null || $current <= 0) {
            throw new DomainException('Indicá la cotización de ' . $currency . ' a la fecha del documento: la empresa no tiene una cargada.');
        }

        return new FrozenExchangeRate($currency, $current, $date, FrozenExchangeRate::SOURCE_COMPANY_CATALOG);
    }
}
