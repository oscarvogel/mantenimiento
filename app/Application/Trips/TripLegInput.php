<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DomainException;

final readonly class TripLegInput
{
    public function __construct(
        public ?int $originDestinationCode,
        public string $origin,
        public string $destination,
        public int $customerId,
        public float $invoiceAmount,
        public float $liquidationAmount,
        public ?float $kilometers = null,
        public ?float $tonnes = null,
        public string $currency = 'ARS',
        public float $exchangeRate = 1.0,
        public ?string $notes = null,
        public ?int $originLocalityId = null,
        public ?int $destinationLocalityId = null,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromInput(array $row): self
    {
        $rawCode = trim((string) ($row['codigo_origen_destino'] ?? ''));
        $routeCode = $rawCode === '' ? null : filter_var($rawCode, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $origin = trim((string) ($row['origen'] ?? ''));
        $destination = trim((string) ($row['destino'] ?? ''));
        if ($routeCode === false) {
            throw new DomainException('El código de ruta anterior debe ser un número positivo.');
        }
        $customerId = filter_var($row['cliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($customerId === false) {
            throw new DomainException('Elegí un cliente para cada tramo.');
        }
        $originLocalityId = filter_var($row['localidad_origen_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $destinationLocalityId = filter_var($row['localidad_destino_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($originLocalityId === false || $destinationLocalityId === false) {
            throw new DomainException('Elegí una localidad de origen y una de destino para cada tramo.');
        }

        return new self(
            $routeCode === null ? null : (int) $routeCode,
            mb_substr($origin, 0, 200),
            mb_substr($destination, 0, 200),
            (int) $customerId,
            self::amount($row['flete_facturacion'] ?? null, 'El flete de facturación'),
            self::amount($row['flete_liquidacion'] ?? null, 'El flete de liquidación'),
            self::optionalAmount($row['km'] ?? null, 'Los kilómetros'),
            self::optionalAmount($row['toneladas'] ?? null, 'Las toneladas'),
            strtoupper(trim((string) ($row['moneda'] ?? 'ARS'))),
            self::rate($row['cotizacion'] ?? null),
            self::nullableText($row['observaciones'] ?? null),
            (int) $originLocalityId,
            (int) $destinationLocalityId,
        );
    }

    private static function amount(mixed $value, string $label): float
    {
        $raw = str_replace(',', '.', trim((string) ($value ?? '')));
        if ($raw === '' || ! is_numeric($raw) || (float) $raw < 0) {
            throw new DomainException($label . ' debe ser un importe mayor o igual a cero.');
        }

        return (float) $raw;
    }

    private static function optionalAmount(mixed $value, string $label): ?float
    {
        $raw = str_replace(',', '.', trim((string) ($value ?? '')));
        if ($raw === '') {
            return null;
        }
        if (! is_numeric($raw) || (float) $raw < 0) {
            throw new DomainException($label . ' no pueden ser negativos.');
        }

        return (float) $raw;
    }

    private static function rate(mixed $value): float
    {
        $raw = str_replace(',', '.', trim((string) ($value ?? '')));
        if ($raw === '') {
            return 1.0;
        }
        if (! is_numeric($raw) || (float) $raw <= 0) {
            throw new DomainException('La cotización del tramo debe ser mayor que cero.');
        }

        return (float) $raw;
    }

    private static function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, 500);
    }
}
