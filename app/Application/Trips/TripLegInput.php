<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Domain\Trips\TripLegAmount;
use DateTimeImmutable;
use DomainException;

final readonly class TripLegInput
{
    /**
     * @param string $liquidationCurrency Moneda del flete de liquidación. Vacia
     *                                    significa que hereda la de facturacion.
     * @param ?float $liquidationExchangeRate Cotizacion del flete de liquidacion.
     *                                         Null significa que hereda la de facturacion.
     */
    public function __construct(
        public ?int $originDestinationCode,
        public string $origin,
        public string $destination,
        public int $customerId,
        public ?float $invoiceAmount,
        public ?float $liquidationAmount,
        public ?float $kilometers = null,
        public ?float $tonnes = null,
        public string $currency = 'ARS',
        public float $exchangeRate = 1.0,
        public ?string $notes = null,
        public ?int $originLocalityId = null,
        public ?int $destinationLocalityId = null,
        public ?DateTimeImmutable $startDate = null,
        public ?DateTimeImmutable $endDate = null,
        public string $liquidationCurrency = '',
        public ?float $liquidationExchangeRate = null,
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

        $currency = strtoupper(trim((string) ($row['moneda'] ?? 'ARS')));
        $liquidationCurrency = strtoupper(trim((string) ($row['moneda_liquidacion'] ?? '')));

        $input = new self(
            $routeCode === null ? null : (int) $routeCode,
            mb_substr($origin, 0, 200),
            mb_substr($destination, 0, 200),
            (int) $customerId,
            self::optionalAmount($row['flete_facturacion'] ?? null, 'El flete de facturación'),
            self::optionalAmount($row['flete_liquidacion'] ?? null, 'El flete de liquidación'),
            self::optionalAmount($row['km'] ?? null, 'Los kilómetros'),
            self::optionalAmount($row['toneladas'] ?? null, 'Las toneladas'),
            $currency,
            self::rate($row['cotizacion'] ?? null, $currency),
            self::nullableText($row['observaciones'] ?? null),
            (int) $originLocalityId,
            (int) $destinationLocalityId,
            TripDateInput::optional($row['fecha_inicio'] ?? null, 'La fecha de inicio del tramo'),
            TripDateInput::optional($row['fecha_fin'] ?? null, 'La fecha de fin del tramo'),
            $liquidationCurrency,
            $liquidationCurrency === '' ? null : self::optionalRate($row['cotizacion_liquidacion'] ?? null),
        );

        // Se resuelve la liquidacion para avisar temprano si le falta la
        // cotizacion que corresponde a su moneda.
        $input->liquidation();

        return $input;
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

    private static function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, 500);
    }
}
