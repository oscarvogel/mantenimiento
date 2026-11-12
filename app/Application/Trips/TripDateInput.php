<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DateTimeImmutable;
use DomainException;

/**
 * Parseo de fechas de viaje y tramo.
 *
 * El sistema maneja fechas sin hora: si llega una hora heredada se descarta.
 */
final class TripDateInput
{
    public static function required(mixed $value, string $label): DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            throw new DomainException($label . ' es obligatoria.');
        }

        return self::parse($raw, $label);
    }

    public static function optional(mixed $value, string $label): ?DateTimeImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return self::required($value, $label);
    }

    private static function parse(string $raw, string $label): DateTimeImmutable
    {
        foreach (['!Y-m-d', '!Y-m-d\TH:i', '!Y-m-d H:i:s', '!Y-m-d\TH:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $raw);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->setTime(0, 0);
            }
        }

        throw new DomainException($label . ' no tiene una fecha válida.');
    }
}
