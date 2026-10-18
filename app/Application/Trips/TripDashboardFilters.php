<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DomainException;

final class TripDashboardFilters
{
    public function __construct(
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?int $branchId = null,
        public readonly ?string $domain = null,
    ) {
    }

    /** @param array<string,mixed> $query */
    public static function fromQuery(array $query): self
    {
        $from = self::date($query['desde'] ?? null, 'La fecha desde');
        $to = self::date($query['hasta'] ?? null, 'La fecha hasta');
        if ($from !== null && $to !== null && $from > $to) {
            throw new DomainException('La fecha desde no puede ser posterior a la fecha hasta.');
        }

        return new self($from, $to, self::positiveInt($query['sucursal'] ?? null), self::domain($query['dominio'] ?? null));
    }

    /** @return array{from:?string,to:?string,branchId:?int,domain:?string} */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'branchId' => $this->branchId, 'domain' => $this->domain];
    }

    private static function date(mixed $value, string $label): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $raw) {
            throw new DomainException($label . ' no es una fecha valida (AAAA-MM-DD).');
        }

        return $raw;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $parsed === false ? null : (int) $parsed;
    }

    private static function domain(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        if (mb_strlen($raw) > 10) {
            throw new DomainException('El dominio no puede superar los 10 caracteres.');
        }

        return mb_strtoupper($raw);
    }
}
