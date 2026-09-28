<?php

declare(strict_types=1);

namespace App\Application\ReadingControl;

use DateTimeImmutable;

/**
 * Semántica de los filtros de antigüedad de la pantalla de control de lecturas.
 *
 * Es la única definición de qué significa cada filtro. A partir del reloj
 * inyectado calcula los límites temporales y los expone de dos formas
 * coherentes entre sí:
 *
 *  - `matches()` para la lógica pura y las pruebas;
 *  - `applyTo()` para el constructor de consultas, de modo que el filtrado
 *    ocurra en SQL y la paginación nunca se calcule sobre una página parcial.
 *
 * Un equipo sin ninguna lectura se considera el caso más antiguo: aparece en
 * `not_today`, `gt_3` y `gt_7`, porque para el operador es el peor caso.
 */
final readonly class ReadingControlFilter
{
    public const ALL = 'all';

    public const TODAY = 'today';

    public const NOT_TODAY = 'not_today';

    public const GT_3 = 'gt_3';

    public const GT_7 = 'gt_7';

    public const KEYS = [self::ALL, self::TODAY, self::NOT_TODAY, self::GT_3, self::GT_7];

    public function __construct(
        public string $key,
        public DateTimeImmutable $now,
    ) {
    }

    public static function fromKey(string $key, DateTimeImmutable $now): self
    {
        return new self(in_array($key, self::KEYS, true) ? $key : self::ALL, $now);
    }

    public function isAll(): bool
    {
        return $this->key === self::ALL;
    }

    /**
     * Inicio del día en curso, usado como límite de "cargaron hoy".
     */
    public function todayStart(): DateTimeImmutable
    {
        return $this->now->setTime(0, 0, 0);
    }

    public function tomorrowStart(): DateTimeImmutable
    {
        return $this->todayStart()->modify('+1 day');
    }

    public function cutoffFor(int $days): DateTimeImmutable
    {
        return $this->now->modify(sprintf('-%d days', max(1, $days)));
    }

    public function daysThreshold(): ?int
    {
        return match ($this->key) {
            self::GT_3 => 3,
            self::GT_7 => 7,
            default => null,
        };
    }

    /**
     * Definición pura del filtro para una fecha de última lectura dada.
     *
     * `null` significa que el equipo nunca tiene una lectura vigente.
     */
    public function matches(?DateTimeImmutable $lastReadingAt): bool
    {
        return match ($this->key) {
            self::TODAY => $lastReadingAt !== null
                && $lastReadingAt >= $this->todayStart()
                && $lastReadingAt < $this->tomorrowStart(),
            self::NOT_TODAY => $lastReadingAt === null
                || $lastReadingAt < $this->todayStart()
                || $lastReadingAt >= $this->tomorrowStart(),
            self::GT_3 => $lastReadingAt === null || $lastReadingAt < $this->cutoffFor(3),
            self::GT_7 => $lastReadingAt === null || $lastReadingAt < $this->cutoffFor(7),
            default => true,
        };
    }

    /**
     * Días transcurridos desde la última lectura, o `null` si nunca hubo lectura.
     *
     * Un equipo sin lectura no se marca con un número enorme: la ausencia de
     * dato es información distinta de "hace muchos días".
     */
    public function daysSince(?DateTimeImmutable $lastReadingAt): ?int
    {
        if ($lastReadingAt === null) {
            return null;
        }

        return max(0, (int) $lastReadingAt->diff($this->now)->format('%a'));
    }

    /**
     * Etiquetas legibles para la interfaz, sin depender del idioma del backend.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function options(): array
    {
        return [
            ['key' => self::ALL, 'label' => 'Todos'],
            ['key' => self::TODAY, 'label' => 'Cargaron hoy'],
            ['key' => self::NOT_TODAY, 'label' => 'Sin cargar hoy'],
            ['key' => self::GT_3, 'label' => 'Más de 3 días'],
            ['key' => self::GT_7, 'label' => 'Más de 7 días'],
        ];
    }
}
