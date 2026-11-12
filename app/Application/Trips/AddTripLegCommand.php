<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Domain\Trips\TripLegAmount;
use DateTimeImmutable;
use DomainException;

final readonly class AddTripLegCommand
{
    /**
     * @param string $liquidationCurrency Moneda del flete de liquidación. Vacio
     *                                    significa que hereda la de facturacion.
     * @param ?float $liquidationExchangeRate Cotizacion del flete de liquidacion.
     *                                         Null significa que hereda la de facturacion.
     */
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
        public string $liquidationCurrency = '',
        public ?float $liquidationExchangeRate = null,
    ) {
    }

    /** @param array<string,mixed> $input */
    public static function fromInput(array $input): self
    {
        $tripId = filter_var($input['viaje_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($tripId === false) {
            throw new DomainException('El viaje del tramo no es válido.');
        }

        $currency = strtoupper(trim((string) ($input['moneda'] ?? 'ARS')));
        $liquidationCurrency = strtoupper(trim((string) ($input['moneda_liquidacion'] ?? '')));

        $command = new self(
            (int) $tripId,
            trim((string) ($input['origen'] ?? '')),
            trim((string) ($input['destino'] ?? '')),
            self::optionalAmount($input['flete_facturacion'] ?? null, 'El flete de facturación'),
            self::optionalAmount($input['flete_liquidacion'] ?? null, 'El flete de liquidación'),
            self::optionalAmount($input['km'] ?? null, 'Los kilómetros'),
            self::requiredPositiveInt($input['cliente_id'] ?? null, 'Elegí un cliente para el tramo.'),
            $currency,
            self::rate($input['cotizacion'] ?? null, $currency),
            self::nullableText($input['observaciones'] ?? null),
            self::optionalPositiveInt($input['codigo_origen_destino'] ?? null, 'El código de ruta anterior no es válido.'),
            self::optionalAmount($input['toneladas'] ?? null, 'Las toneladas'),
            self::requiredPositiveInt($input['localidad_origen_id'] ?? null, 'Elegí una localidad de origen para el tramo.'),
            self::requiredPositiveInt($input['localidad_destino_id'] ?? null, 'Elegí una localidad de destino para el tramo.'),
            TripDateInput::optional($input['fecha_inicio'] ?? null, 'La fecha de inicio del tramo'),
            TripDateInput::optional($input['fecha_fin'] ?? null, 'La fecha de fin del tramo'),
            $liquidationCurrency,
            $liquidationCurrency === '' ? null : self::optionalRate($input['cotizacion_liquidacion'] ?? null),
        );

        // Se resuelve la liquidacion para avisar temprano si le falta la
        // cotizacion que corresponde a su moneda.
        $command->liquidation();

        return $command;
    }

    /**
     * Flete de facturacion con su moneda y su cotizacion.
     */
    public function invoice(): TripLegAmount
    {
        return new TripLegAmount($this->invoiceAmount, $this->currency, $this->exchangeRate);
    }

    /**
     * Flete de liquidacion con su moneda y su cotizacion.
     *
     * El flete de liquidacion se puede cargar en una moneda distinta al de
     * facturacion: en pesos no hace falta convertir nada. Si el operador no
     * indica moneda, hereda la de facturacion, que es como se cargo siempre.
     */
    public function liquidation(): TripLegAmount
    {
        $currency = $this->liquidationCurrency === ''
            ? strtoupper(trim($this->currency))
            : strtoupper(trim($this->liquidationCurrency));

        if ($currency === 'ARS') {
            return new TripLegAmount($this->liquidationAmount, 'ARS', 1.0);
        }

        $rate = $this->liquidationExchangeRate;
        if ($rate === null && $currency === strtoupper(trim($this->currency))) {
            $rate = $this->exchangeRate;
        }
        if ($rate === null) {
            throw new DomainException('Ingresá la cotización a ARS para el flete de liquidación en ' . $currency . '.');
        }

        return new TripLegAmount($this->liquidationAmount, $currency, $rate);
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
        $parsed = self::optionalRate($value);
        if ($parsed !== null) {
            return $parsed;
        }
        if ($currency === 'ARS') {
            return 1.0;
        }

        throw new DomainException('Ingresá la cotización a ARS para el flete de facturación en ' . $currency . '.');
    }

    private static function optionalRate(mixed $value): ?float
    {
        $raw = str_replace(',', '.', trim((string) ($value ?? '')));
        if ($raw === '') {
            return null;
        }
        if (! is_numeric($raw) || (float) $raw <= 0) {
            throw new DomainException('La cotización del flete debe ser mayor que cero.');
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
