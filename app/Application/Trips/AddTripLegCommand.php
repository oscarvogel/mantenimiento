<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DateTimeImmutable;
use DomainException;

final readonly class AddTripLegCommand
{
    public function __construct(
        public int $tripId,
        public string $origin,
        public string $destination,
        public ?float $invoiceAmount,
        public ?float $liquidationAmount,
        public ?float $kilometers = null,
        public ?int $customerId = null,
        public string $currency = 'ARS',
        public float $exchangeRate = 1.0,
        public ?string $notes = null,
        public ?int $originDestinationCode = null,
        public ?float $tonnes = null,
        public ?int $originLocalityId = null,
        public ?int $destinationLocalityId = null,
        public ?DateTimeImmutable $startDate = null,
        public ?DateTimeImmutable $endDate = null,
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
            self::optionalAmount($input['flete_facturacion'] ?? null, 'El flete de facturación'),
            self::optionalAmount($input['flete_liquidacion'] ?? null, 'El flete de liquidación'),
            self::optionalAmount($input['km'] ?? null, 'Los kilómetros'),
            self::requiredPositiveInt($input['cliente_id'] ?? null, 'Elegí un cliente para el tramo.'),
            strtoupper(trim((string) ($input['moneda'] ?? 'ARS'))),
            self::rate($input['cotizacion'] ?? null, strtoupper(trim((string) ($input['moneda'] ?? 'ARS')))),
            self::nullableText($input['observaciones'] ?? null),
            self::optionalPositiveInt($input['codigo_origen_destino'] ?? null, 'El código de ruta anterior no es válido.'),
            self::optionalAmount($input['toneladas'] ?? null, 'Las toneladas'),
            self::requiredPositiveInt($input['localidad_origen_id'] ?? null, 'Elegí una localidad de origen para el tramo.'),
            self::requiredPositiveInt($input['localidad_destino_id'] ?? null, 'Elegí una localidad de destino para el tramo.'),
            TripDateInput::optional($input['fecha_inicio'] ?? null, 'La fecha de inicio del tramo'),
            TripDateInput::optional($input['fecha_fin'] ?? null, 'La fecha de fin del tramo'),
        );
    }

    private static function optionalAmount(mixed $value, string $label): ?float
    {
        $raw = str_replace(',', '.', trim((string) ($value ?? '')));
        if ($raw === '') {
            return null;
        }
        if (! is_numeric($raw) || (float) $raw < 0) {
            throw new DomainException($label . ' no puede ser negativo.');
        }

        return (float) $raw;
    }

    private static function rate(mixed $value, string $currency): float
    {
        $raw = str_replace(',', '.', trim((string) ($value ?? '')));
        if ($raw === '') {
            if ($currency === 'ARS') {
                return 1.0;
            }

            throw new DomainException('Ingresá la cotización a ARS para el tramo en ' . $currency . '.');
        }
        if (! is_numeric($raw) || (float) $raw <= 0) {
            throw new DomainException('La cotización del tramo debe ser mayor que cero.');
        }

        return (float) $raw;
    }

    private static function requiredPositiveInt(mixed $value, string $message): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parsed === false) {
            throw new DomainException($message);
        }
        return (int) $parsed;
    }

    private static function optionalPositiveInt(mixed $value, string $message): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return self::requiredPositiveInt($value, $message);
    }

    private static function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, 500);
    }
}
