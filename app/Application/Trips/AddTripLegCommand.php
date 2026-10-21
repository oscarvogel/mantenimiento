<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DomainException;

final readonly class AddTripLegCommand
{
    public function __construct(
        public int $tripId,
        public string $origin,
        public string $destination,
        public float $invoiceAmount,
        public float $liquidationAmount,
        public float $kilometers = 0.0,
        public ?int $customerId = null,
        public string $currency = 'ARS',
        public float $exchangeRate = 1.0,
        public ?string $notes = null,
    ) {
    }

    /** @param array<string,mixed> $input */
    public static function fromInput(array $input): self
    {
        $tripId = filter_var($input['viaje_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($tripId === false) {
            throw new DomainException('El viaje del tramo no es válido.');
        }

        return new self(
            (int) $tripId,
            trim((string) ($input['origen'] ?? '')),
            trim((string) ($input['destino'] ?? '')),
            self::amount($input['flete_facturacion'] ?? null, 'El flete de facturación'),
            self::amount($input['flete_liquidacion'] ?? null, 'El flete de liquidación'),
            self::optionalAmount($input['km'] ?? null, 'Los kilómetros'),
            self::optionalPositiveInt($input['cliente_id'] ?? null, 'El cliente del tramo no es válido.'),
            strtoupper(trim((string) ($input['moneda'] ?? 'ARS'))),
            self::rate($input['cotizacion'] ?? null),
            self::nullableText($input['observaciones'] ?? null),
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

    private static function optionalAmount(mixed $value, string $label): float
    {
        $raw = str_replace(',', '.', trim((string) ($value ?? '')));
        if ($raw === '') {
            return 0.0;
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

    private static function optionalPositiveInt(mixed $value, string $message): ?int
    {
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parsed === false) {
            throw new DomainException($message);
        }

        return (int) $parsed;
    }

    private static function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, 500);
    }
}
