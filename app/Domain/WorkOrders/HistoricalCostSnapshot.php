<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

use DateTimeImmutable;
use DomainException;

final class HistoricalCostSnapshot
{
    private function __construct(
        private readonly string $currency,
        private readonly string $originalAmount,
        private readonly string $exchangeRateArs,
        private readonly string $exchangeRateDate,
        private readonly string $exchangeRateSource,
        private readonly string $amountArs,
    ) {}

    public static function fromInput(
        string $currency,
        string|int|float $originalAmount,
        string|int|float|null $exchangeRate,
        ?string $exchangeRateDate,
        ?string $exchangeRateSource,
        string $serviceDate,
    ): self {
        $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainException('La moneda debe informarse con un código ISO de 3 letras.');
        }

        $amount = self::decimal($originalAmount, 'El importe original');
        if ((float) $amount < 0) {
            throw new DomainException('El importe original no puede ser negativo.');
        }

        if ($currency === 'ARS') {
            self::assertDate($serviceDate, 'La fecha del trabajo');
            return new self('ARS', $amount, '1.000000', $serviceDate, 'ARS', self::money($amount));
        }

        if ($exchangeRate === null || trim((string) $exchangeRate) === '') {
            throw new DomainException('Ingresá el tipo de cambio histórico antes de confirmar una OT en moneda extranjera.');
        }
        $rate = self::decimal($exchangeRate, 'El tipo de cambio');
        if ((float) $rate <= 0) {
            throw new DomainException('El tipo de cambio debe ser mayor que cero.');
        }

        $quoteDate = trim((string) $exchangeRateDate);
        self::assertDate($quoteDate, 'La fecha de cotización');
        if ($quoteDate > $serviceDate) {
            throw new DomainException('La fecha de cotización no puede ser posterior a la fecha del trabajo.');
        }

        $source = trim((string) $exchangeRateSource);
        if ($source === '') {
            throw new DomainException('Indicá el origen de la cotización histórica.');
        }

        return new self(
            $currency,
            $amount,
            number_format((float) $rate, 6, '.', ''),
            $quoteDate,
            mb_substr($source, 0, 32),
            self::money((float) $amount * (float) $rate),
        );
    }

    /** @return array{moneda_original:string,importe_original:string,tipo_cambio_ars:string,fecha_tipo_cambio:string,origen_tipo_cambio:string,importe_ars:string} */
    public function toPersistence(): array
    {
        return [
            'moneda_original' => $this->currency,
            'importe_original' => $this->originalAmount,
            'tipo_cambio_ars' => $this->exchangeRateArs,
            'fecha_tipo_cambio' => $this->exchangeRateDate,
            'origen_tipo_cambio' => $this->exchangeRateSource,
            'importe_ars' => $this->amountArs,
        ];
    }

    private static function decimal(string|int|float $value, string $label): string
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        if ($normalized === '' || ! is_numeric($normalized)) {
            throw new DomainException($label . ' no es válido.');
        }
        return number_format((float) $normalized, 2, '.', '');
    }

    private static function money(string|int|float $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    private static function assertDate(string $value, string $label): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new DomainException($label . ' no es válida.');
        }
    }
}
