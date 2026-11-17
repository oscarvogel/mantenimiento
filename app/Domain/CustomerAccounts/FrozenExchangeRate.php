<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccounts;

use DateTimeImmutable;
use DomainException;

/**
 * Cotización definitiva congelada en el evento que hace nacer la deuda (#530).
 *
 * Se guarda con su fecha y su fuente. Un cambio posterior del dólar o del real,
 * o del catálogo de cotizaciones de la empresa, nunca la recalcula.
 */
final readonly class FrozenExchangeRate
{
    public const SOURCE_COMPANY_CATALOG = 'CATALOGO_EMPRESA';
    public const SOURCE_MANUAL = 'MANUAL';
    public const SOURCE_PESOS = 'PESOS';

    private const SOURCES = [self::SOURCE_COMPANY_CATALOG, self::SOURCE_MANUAL, self::SOURCE_PESOS];

    private string $currency;
    private float $rate;
    private string $source;

    public function __construct(
        string $currency,
        float $rate,
        private DateTimeImmutable $date,
        string $source,
    ) {
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new DomainException('La moneda debe tener tres letras.');
        }
        if ($currency === 'ARS') {
            // Los pesos no se cotizan: la tasa es 1 y la fuente lo dice.
            $rate = 1.0;
            $source = self::SOURCE_PESOS;
        }
        if ($rate <= 0) {
            throw new DomainException('La cotización definitiva debe ser mayor que cero.');
        }
        if (! in_array($source, self::SOURCES, true)) {
            throw new DomainException('La fuente de la cotización no es válida.');
        }

        $this->currency = $currency;
        $this->rate = $rate;
        $this->source = $source;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function rate(): float
    {
        return $this->rate;
    }

    public function date(): DateTimeImmutable
    {
        return $this->date;
    }

    public function source(): string
    {
        return $this->source;
    }

    /** Pesifica un importe en la moneda de la cotización, redondeado a centavos. */
    public function toArs(float $amount): float
    {
        return round($amount * $this->rate, 2, PHP_ROUND_HALF_UP);
    }
}
