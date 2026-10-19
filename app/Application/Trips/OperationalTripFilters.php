<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Domain\Trips\Trip;
use DateTimeImmutable;
use DomainException;

final readonly class OperationalTripFilters
{
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public ?int $branchId = null,
        public ?string $status = null,
        public ?string $search = null,
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

        return new self(
            $from,
            $to,
            self::positiveInt($query['sucursal'] ?? null),
            self::status($query['estado'] ?? null),
            self::search($query['buscar'] ?? null),
        );
    }

    /** @return array{from:?string,to:?string,branchId:?int,status:?string,search:?string} */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'branchId' => $this->branchId,
            'status' => $this->status,
            'search' => $this->search,
        ];
    }

    private static function date(mixed $value, string $label): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        $errors = DateTimeImmutable::getLastErrors();
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

    private static function status(mixed $value): ?string
    {
        $raw = strtoupper(trim((string) ($value ?? '')));
        if ($raw === '') {
            return null;
        }
        if (! in_array($raw, [Trip::PLANIFICADO, Trip::EN_CURSO, Trip::FINALIZADO, Trip::CANCELADO], true)) {
            throw new DomainException('El estado de viaje indicado no es válido.');
        }

        return $raw;
    }

    private static function search(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        if (mb_strlen($raw) > 80) {
            throw new DomainException('La búsqueda no puede superar los 80 caracteres.');
        }

        return $raw;
    }
}
