<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DateTimeImmutable;
use DomainException;

final readonly class CreateTripCommand
{
    /**
     * @param list<int> $driverIds
     * @param list<TripStopInput> $stops
     * @param array<int,float|null> $driverPercentages
     */
    public function __construct(
        public int $branchId,
        public DateTimeImmutable $plannedDeparture,
        public DateTimeImmutable $plannedReturn,
        public int $truckId,
        public ?int $trailerId,
        public array $driverIds,
        public array $stops,
        public string $notes = '',
        public ?int $departureKm = null,
        public array $driverPercentages = [],
    ) {
    }

    /** @param array<string,mixed> $input */
    public static function fromInput(array $input): self
    {
        $branchId = self::positiveInt($input['sucursal_id'] ?? null, 'Elegí la sucursal de origen.');
        $truckId = self::positiveInt($input['tractor_id'] ?? null, 'Elegí el tractor o camión del viaje.');
        $trailerId = self::optionalPositiveInt($input['acoplado_id'] ?? null, 'El acoplado elegido no es válido.');
        $plannedDeparture = self::dateTime($input['fecha_salida_planificada'] ?? null, 'La salida planificada');
        $plannedReturn = self::dateTime($input['fecha_regreso_planificada'] ?? null, 'El regreso planificado');
        if ($plannedReturn <= $plannedDeparture) {
            throw new DomainException('El regreso planificado debe ser posterior a la salida planificada.');
        }

        $driverIds = self::driverIds($input['choferes'] ?? null);
        if ($driverIds === []) {
            throw new DomainException('Asigná al menos un chofer al viaje.');
        }

        $stops = self::stops($input['paradas'] ?? null);
        if (count($stops) < 2) {
            throw new DomainException('Cargá al menos una parada de salida y una de llegada.');
        }

        return new self(
            $branchId,
            $plannedDeparture,
            $plannedReturn,
            $truckId,
            $trailerId,
            $driverIds,
            $stops,
            trim((string) ($input['observaciones'] ?? '')),
            self::optionalNonNegativeInt($input['km_salida'] ?? null, 'El odómetro de salida no es válido.'),
            self::driverPercentages($input['choferes_porcentajes'] ?? null, $driverIds),
        );
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

    private static function optionalNonNegativeInt(mixed $value, string $message): ?int
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
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

    /** @return list<TripStopInput> */
    private static function stops(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $stops = [];
        foreach ($value as $row) {
            if (! is_array($row) || self::emptyStop($row)) {
                continue;
            }
            $type = strtoupper(trim((string) ($row['tipo'] ?? '')));
            if ($type === '') {
                throw new DomainException('Elegí el tipo de cada parada.');
            }
            $location = trim((string) ($row['ubicacion'] ?? ''));
            if ($location === '') {
                throw new DomainException('Completá la ubicación de cada parada.');
            }
            $stops[] = new TripStopInput(
                $type,
                $location,
                self::optionalPositiveInt($row['cliente_id'] ?? null, 'El cliente de la parada no es válido.'),
                self::optionalPositiveInt($row['domicilio_id'] ?? null, 'El domicilio de la parada no es válido.'),
                self::optionalDateTime($row['fecha_estimada'] ?? null, 'La fecha estimada de la parada'),
                self::nullableText($row['descripcion_carga'] ?? null),
                self::nullableText($row['cantidad'] ?? null),
                self::optionalPositiveInt($row['unidad_carga_id'] ?? null, 'La unidad de carga no es válida.'),
                self::nullableText($row['observaciones'] ?? null),
            );
        }

        return $stops;
    }

    /** @param array<string,mixed> $row */
    private static function emptyStop(array $row): bool
    {
        foreach (['tipo', 'ubicacion', 'cliente_id', 'domicilio_id', 'fecha_estimada', 'descripcion_carga', 'cantidad', 'unidad_carga_id', 'observaciones'] as $field) {
            if (trim((string) ($row[$field] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function optionalDateTime(mixed $value, string $label): ?DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
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

    private static function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
