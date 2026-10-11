<?php

declare(strict_types=1);

namespace App\Domain\Telematic;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Posición reportada por una fuente de telemetría.
 *
 * El instante va siempre adentro. Una posición sin su hora es un punto en el
 * mapa que manda a un chofer a donde el camión estuvo hace una semana: por
 * eso `observadaEn` no es un dato opcional de la vista, es parte del dato.
 */
final readonly class Posicion
{
    public function __construct(
        private float $latitude,
        private float $longitude,
        private ?float $speedKmh,
        private ?int $course,
        private ?float $altitudeMeters,
        private ?int $satellites,
        private DateTimeImmutable $observadaEn,
    ) {
        if ($latitude < -90.0 || $latitude > 90.0) {
            throw new InvalidArgumentException('La latitud está fuera de rango.');
        }
        if ($longitude < -180.0 || $longitude > 180.0) {
            throw new InvalidArgumentException('La longitud está fuera de rango.');
        }
    }

    public function latitude(): float
    {
        return $this->latitude;
    }

    public function longitude(): float
    {
        return $this->longitude;
    }

    public function speedKmh(): ?float
    {
        return $this->speedKmh;
    }

    public function course(): ?int
    {
        return $this->course;
    }

    public function altitudeMeters(): ?float
    {
        return $this->altitudeMeters;
    }

    public function satellites(): ?int
    {
        return $this->satellites;
    }

    public function observadaEn(): DateTimeImmutable
    {
        return $this->observadaEn;
    }

    public function estaEnMovimiento(): bool
    {
        return ($this->speedKmh ?? 0.0) > 1.0;
    }

    public function antiguedadMinutos(DateTimeImmutable $ahora): int
    {
        $segundos = $ahora->getTimestamp() - $this->observadaEn->getTimestamp();

        return intdiv($segundos, 60);
    }
}