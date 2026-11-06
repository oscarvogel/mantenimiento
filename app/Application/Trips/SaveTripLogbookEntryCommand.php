<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DateTimeImmutable;
use DomainException;

final readonly class SaveTripLogbookEntryCommand
{
    public function __construct(
        public int $tripId,
        public string $type,
        public string $location,
        public DateTimeImmutable $arrivedAt,
        public ?DateTimeImmutable $departedAt,
        public int $freeHours,
        public int $employeeId,
        public int $conceptId,
        public ?float $adjustedDays = null,
        public ?string $adjustmentReason = null,
        public ?string $notes = null,
        public ?int $entryId = null,
    ) {
    }

    /** @param array<string,mixed> $input */
    public static function fromInput(array $input): self
    {
        $tripId = self::positiveInt($input['viaje_id'] ?? null, 'El viaje del diario de bordo no es válido.');
        $type = strtoupper(trim((string) ($input['tipo'] ?? '')));
        if ($type === '') {
            throw new DomainException('Elegí el tipo de etapa del diario de bordo.');
        }
        $location = trim((string) ($input['ubicacion'] ?? ''));
        if ($location === '') {
            throw new DomainException('Indicá la ubicación de la etapa.');
        }

        return new self(
            $tripId,
            $type,
            mb_substr($location, 0, 200),
            self::date($input['llegada'] ?? null, 'La fecha de llegada'),
            self::optionalDate($input['salida'] ?? null, 'La fecha de salida'),
            self::freeHours($input['horas_libres'] ?? null),
            self::positiveInt($input['empleado_id'] ?? null, 'Elegí el chofer de la estadía.'),
            self::positiveInt($input['concepto_id'] ?? null, 'Elegí el concepto de la estadía.'),
            self::optionalDays($input['dias_ajustados'] ?? null),
            self::nullableText($input['motivo_ajuste'] ?? null, 500),
            self::nullableText($input['observaciones'] ?? null, 500),
            self::optionalPositiveInt($input['id'] ?? null),
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

    private static function optionalPositiveInt(mixed $value): ?int
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        $parsed = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parsed === false) {
            throw new DomainException('La etapa del diario de bordo no es válida.');
        }

        return (int) $parsed;
    }

    private static function freeHours(mixed $value): int
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return 48;
        }
        $parsed = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($parsed === false) {
            throw new DomainException('La franquicia de horas debe ser un número no negativo.');
        }

        return (int) $parsed;
    }

    private static function optionalDays(mixed $value): ?float
    {
        $raw = str_replace(',', '.', trim((string) ($value ?? '')));
        if ($raw === '') {
            return null;
        }
        if (! is_numeric($raw) || (float) $raw < 0) {
            throw new DomainException('Los días a liquidar deben ser un número no negativo.');
        }

        return round((float) $raw, 2);
    }

    private static function date(mixed $value, string $label): DateTimeImmutable
    {
        $date = self::optionalDate($value, $label);
        if ($date === null) {
            throw new DomainException($label . ' es obligatoria.');
        }

        return $date;
    }

    private static function optionalDate(mixed $value, string $label): ?DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        foreach (['!Y-m-d', '!Y-m-d\TH:i', '!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $raw);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->setTime(0, 0);
            }
        }

        throw new DomainException($label . ' no es válida.');
    }

    private static function nullableText(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
