<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Domain\Trips\Trip;
use DateTimeImmutable;
use DomainException;

final readonly class OperationalTripFilters
{
    /**
     * Estado derivado: el recorrido terminó (todos sus tramos tienen fecha de
     * fin) pero el viaje sigue en curso porque todavía no se lo cerró.
     * No es un estado del dominio: es lo que ve el operador en el listado.
     */
    public const PENDIENTE_CIERRE = 'PENDIENTE_CIERRE';

    public const ORDER_ASC = 'asc';

    public const ORDER_DESC = 'desc';

    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public ?int $branchId = null,
        public ?string $status = null,
        public ?string $search = null,
        public ?string $plate = null,
        public string $order = self::ORDER_DESC,
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
            self::plate($query['patente'] ?? null),
            self::order($query['orden'] ?? null),
        );
    }

    /** @return array{from:?string,to:?string,branchId:?int,status:?string,search:?string,plate:?string,order:string} */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'branchId' => $this->branchId,
            'status' => $this->status,
            'search' => $this->search,
            'plate' => $this->plate,
            'order' => $this->order,
        ];
    }

    /** El listado arranca de más nuevo a más viejo; el operador puede invertirlo. */
    public function isAscending(): bool
    {
        return $this->order === self::ORDER_ASC;
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
        if ($parsed === false) {
            return null;
        }

        return (int) $parsed;
    }

    private static function status(mixed $value): ?string
    {
        $raw = strtoupper(trim((string) ($value ?? '')));
        if ($raw === '') {
            return null;
        }
        $allowed = [Trip::PLANIFICADO, Trip::EN_CURSO, Trip::FINALIZADO, Trip::CANCELADO, self::PENDIENTE_CIERRE];
        if (! in_array($raw, $allowed, true)) {
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

    /**
     * Patente del equipo: tractor o semirremolque. Se busca por coincidencia
     * parcial, sin distinguir mayúsculas.
     */
    private static function plate(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        if (mb_strlen($raw) > 20) {
            throw new DomainException('La patente no puede superar los 20 caracteres.');
        }
        // Los comodines quedan fuera: la patente es alfanumérica.
        $raw = str_replace(['%', '_'], '', $raw);
        if ($raw === '') {
            return null;
        }

        return $raw;
    }

    private static function order(mixed $value): string
    {
        $raw = strtolower(trim((string) ($value ?? '')));
        if ($raw === '') {
            return self::ORDER_DESC;
        }
        if (! in_array($raw, [self::ORDER_ASC, self::ORDER_DESC], true)) {
            throw new DomainException('El orden indicado no es válido.');
        }

        return $raw;
    }
}
