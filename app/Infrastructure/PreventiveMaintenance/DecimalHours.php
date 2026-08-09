<?php

declare(strict_types=1);

namespace App\Infrastructure\PreventiveMaintenance;

use UnexpectedValueException;

final class DecimalHours
{
    public static function toTenths(int|float|string|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = is_float($value) ? number_format($value, 1, '.', '') : trim((string) $value);
        if (! preg_match('/^(\d+)(?:\.(\d))?$/', $normalized, $matches)) {
            throw new UnexpectedValueException('El horometro persistido no tiene una cifra decimal valida.');
        }

        return ((int) $matches[1] * 10) + (int) ($matches[2] ?? 0);
    }

    public static function fromTenths(?int $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return intdiv($value, 10) . '.' . ($value % 10);
    }
}
