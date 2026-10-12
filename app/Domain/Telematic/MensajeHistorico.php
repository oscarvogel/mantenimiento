<?php

declare(strict_types=1);

namespace App\Domain\Telematic;

use DateTimeImmutable;
use InvalidArgumentException;

/** Un mensaje histórico ya traducido al vocabulario de telemetría del producto. */
final readonly class MensajeHistorico
{
    public function __construct(
        private DateTimeImmutable $observadaEn,
        private ?float $combustibleLitros,
        private ?int $kilometraje,
        private ?int $horasDecimales,
        private ?bool $motorEncendido,
        private ?bool $ralentiActivo,
        private ?Posicion $posicion = null,
        /** @var array<string,mixed> */
        private array $datosFuente = [],
    ) {
        if ($combustibleLitros !== null && (! is_finite($combustibleLitros) || $combustibleLitros < 0.0)) {
            throw new InvalidArgumentException('El combustible de un mensaje histórico debe ser finito y no negativo.');
        }

        if ($kilometraje !== null && $kilometraje < 0) {
            throw new InvalidArgumentException('El kilometraje de un mensaje histórico no puede ser negativo.');
        }

        if ($horasDecimales !== null && $horasDecimales < 0) {
            throw new InvalidArgumentException('Las horas de un mensaje histórico no pueden ser negativas.');
        }
    }

    public function observadaEn(): DateTimeImmutable
    {
        return $this->observadaEn;
    }

    public function combustibleLitros(): ?float
    {
        return $this->combustibleLitros;
    }

    public function kilometraje(): ?int
    {
        return $this->kilometraje;
    }

    public function horasDecimales(): ?int
    {
        return $this->horasDecimales;
    }

    public function motorEncendido(): ?bool
    {
        return $this->motorEncendido;
    }

    public function ralentiActivo(): ?bool
    {
        return $this->ralentiActivo;
    }

    public function posicion(): ?Posicion
    {
        return $this->posicion;
    }

    /** @return array<string,mixed> */
    public function datosFuente(): array
    {
        return $this->datosFuente;
    }
}
