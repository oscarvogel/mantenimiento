<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DateTimeImmutable;
use DomainException;

final readonly class RegisterTripOtherEarningCommand
{
    public function __construct(
        public int $tripId,
        public int $employeeId,
        public DateTimeImmutable $date,
        public float $amount,
        public string $currency = 'ARS',
        public float $exchangeRate = 1.0,
        public string $concept = '',
        public ?string $notes = null,
    ) {
    }

    /** @param array<string,mixed> $input */
    public static function fromInput(array $input): self
    {
        $tripId = self::positiveInt($input['viaje_id'] ?? null, 'El viaje del haber no es válido.');
        $employeeId = self::positiveInt($input['empleado_id'] ?? null, 'Elegí el chofer del haber.');
        $amount = self::amount($input['importe'] ?? null);
        $currency = strtoupper(trim((string) ($input['moneda'] ?? 'ARS')));
        $rate = self::rate($input['cotizacion'] ?? null);
        $concept = trim((string) ($input['concepto'] ?? ''));
        if ($concept === '') {
            throw new DomainException('Indicá el concepto del haber.');
        }

        return new self(
            $tripId,
            $employeeId,
            self::date($input['fecha'] ?? null),
            $amount,
            $currency,
            $rate,
            mb_substr($concept, 0, 120),
            self::nullableText($input['observaciones'] ?? null),
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

    private static function amount(mixed $value): float
    {
        $raw = str_replace(',', '.', trim((string) ($value ?? '')));
        if ($raw === '' || ! is_numeric($raw) || (float) $raw <= 0) {
            throw new DomainException('El importe del haber debe ser mayor que cero.');
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
            throw new DomainException('La cotización del haber debe ser mayor que cero.');
        }

        return (float) $raw;
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            throw new DomainException('Indicá la fecha del haber.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new DomainException('La fecha del haber no es válida.');
        }

        return $date;
    }

    private static function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, 500);
    }
}
