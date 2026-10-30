<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Domain\Trips\Trip;
use DateTimeImmutable;
use DomainException;

final readonly class CreateTripCommand
{
    /**
     * @param list<int> $driverIds
     * @param list<int> $trailerIds
     * @param list<TripLegInput> $legs
     * @param array<int,float|null> $driverPercentages
     */
    public function __construct(
        public int $branchId,
        public DateTimeImmutable $departureAt,
        public DateTimeImmutable $estimatedReturnAt,
        public int $truckId,
        public array $trailerIds,
        public array $driverIds,
        public array $legs,
        public int $departureKm,
        public string $notes = '',
        public array $driverPercentages = [],
    ) {
    }

    /** @param array<string,mixed> $input */
    public static function fromInput(array $input): self
    {
        $branchId = self::positiveInt($input['sucursal_id'] ?? null, 'Elegí la sucursal de origen.');
        $truckId = self::positiveInt($input['tractor_id'] ?? null, 'Elegí el tractor o camión del viaje.');
        $trailerIds = self::trailerIds($input['acoplado_id'] ?? null);
        $departureAt = self::dateTime($input['fecha_salida_real'] ?? null, 'La fecha y hora real de salida');
        $estimatedReturnAt = self::dateTime($input['fecha_regreso_estimado'] ?? null, 'La fecha estimada de regreso');
        if ($estimatedReturnAt <= $departureAt) {
            throw new DomainException('El regreso estimado debe ser posterior a la salida real.');
        }
        $departureKm = self::nonNegativeInt($input['km_salida'] ?? null, 'Ingresá el odómetro real de salida.');

        $driverIds = self::driverIds($input['choferes'] ?? null);
        if ($driverIds === []) {
            throw new DomainException('Asigná al menos un chofer al viaje.');
        }

        $legs = self::legs($input['tramos'] ?? null);
        if ($legs === []) {
            throw new DomainException('Agregá al menos un tramo con origen, destino, cliente y fletes.');
        }

        return new self(
            $branchId,
            $departureAt,
            $estimatedReturnAt,
            $truckId,
            $trailerIds,
            $driverIds,
            $legs,
            $departureKm,
            trim((string) ($input['observaciones'] ?? '')),
            self::driverPercentages($input['choferes_porcentajes'] ?? null, $driverIds),
        );
    }

    /**
     * Semirremolques de la composición.
     *
     * Acepta el valor suelto (un acoplado) y la lista (bitrén o tridem), que es
     * lo que manda el formulario.
     *
     * @return list<int>
     */
    private static function trailerIds(mixed $value): array
    {
        $candidates = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
        $ids = [];
        foreach ($candidates as $candidate) {
            $parsed = filter_var($candidate, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($parsed === false) {
                throw new DomainException('El semirremolque elegido no es válido.');
            }
            $ids[(int) $parsed] = (int) $parsed;
        }
        $ids = array_values($ids);
        if (count($ids) > Trip::MAX_TRAILERS) {
            throw new DomainException('El viaje admite hasta ' . Trip::MAX_TRAILERS . ' semirremolques (bitrén o tridem).');
        }

        return $ids;
    }

    private static function positiveInt(mixed $value, string $message): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parsed === false) {
            throw new DomainException($message);
        }

        return (int) $parsed;
    }

    private static function optionalPositiveInt(mixed $value, string $message): ?int
    {
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }

        return self::positiveInt($value, $message);
    }

    private static function nonNegativeInt(mixed $value, string $message): int
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            throw new DomainException($message);
        }
        $parsed = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($parsed === false) {
            throw new DomainException($message);
        }

        return (int) $parsed;
    }

    /**
     * @param list<int> $driverIds
     * @return array<int,float|null>
     */
    private static function driverPercentages(mixed $value, array $driverIds): array
    {
        if (! is_array($value)) {
            return [];
        }

        $selected = array_fill_keys($driverIds, true);
        $percentages = [];
        foreach ($value as $driverId => $percentage) {
            $id = (int) $driverId;
            if (! isset($selected[$id])) {
                continue;
            }
            $raw = str_replace(',', '.', trim((string) $percentage));
            if ($raw === '') {
                $percentages[$id] = null;
                continue;
            }
            if (! is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100) {
                throw new DomainException('El porcentaje de liquidación debe estar entre 0 y 100.');
            }
            $percentages[$id] = (float) $raw;
        }

        return $percentages;
    }

    private static function dateTime(mixed $value, string $label): DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            throw new DomainException($label . ' es obligatoria.');
        }
        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i:s', '!Y-m-d\TH:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $raw);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }

        throw new DomainException($label . ' no tiene una fecha y hora válidas.');
    }

    /** @return list<int> */
    private static function driverIds(mixed $value): array
    {
        if ($value === null || ! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $candidate) {
            $parsed = filter_var($candidate, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($parsed !== false) {
                $ids[(int) $parsed] = (int) $parsed;
            }
        }

        return array_values($ids);
    }

    /** @return list<TripLegInput> */
    private static function legs(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $legs = [];
        foreach ($value as $row) {
            if (! is_array($row) || self::emptyLeg($row)) {
                continue;
            }
            $legs[] = TripLegInput::fromInput($row);
        }

        return $legs;
    }

    /** @param array<string,mixed> $row */
    private static function emptyLeg(array $row): bool
    {
        foreach (['codigo_origen_destino', 'origen', 'destino', 'cliente_id', 'km', 'toneladas', 'flete_facturacion', 'flete_liquidacion', 'observaciones'] as $field) {
            if (trim((string) ($row[$field] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
