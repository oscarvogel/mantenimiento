<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DomainException;

/**
 * Filtros del módulo de Combustible: qué viajes se miran.
 *
 * Son los que el operador usa a diario: un rango de fechas y un camión. La
 * fecha es la **salida real** del viaje: un viaje que empezó antes del
 * período y sigue en curso entra igual, porque hoy sigue consumiendo.
 */
final readonly class FuelPanelFilters
{
    /** Un año es el tope: más que eso la pantalla deja de ser operable. */
    public const DIAS_MAXIMO = 366;

    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public ?int $equipmentId = null,
        public ?int $branchId = null,
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
        if ($from !== null && $to !== null) {
            $dias = (int) ((strtotime($to) - strtotime($from)) / 86400);
            if ($dias > self::DIAS_MAXIMO) {
                throw new DomainException('El período no puede superar un año.');
            }
        }

        return new self(
            $from,
            $to,
            self::positiveInt($query['camion'] ?? null),
            self::positiveInt($query['sucursal'] ?? null),
        );
    }

    /** @return array{from:?string,to:?string,equipmentId:?int,branchId:?int} */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'equipmentId' => $this->equipmentId,
            'branchId' => $this->branchId,
        ];
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
}
