<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

/**
 * El resultado de leer una serie y calcular el consumo de un equipo.
 *
 * Lleva la cobertura del período y los umbrales que se aplicaron, además de la
 * bandera `sinCalibrar`. Las tres cosas son necesarias para que quien mira
 * pueda distinguir "no hubo consumo" de "no sabemos" y "esto es un número
 * medido" de "esto sale de umbrales que todavía nadie verificó".
 */
final readonly class ReporteConsumo
{
    /**
     * @param list<ViajeConsumido> $viajes
     */
    public function __construct(
        private int $equipoId,
        private array $viajes,
        private CoberturaPeriodo $cobertura,
        private int $muestrasLeidas,
        private array $umbrales,
        private bool $sinCalibrar,
        private DistribucionCombustible $distribucion,
    ) {
    }

    public function equipoId(): int
    {
        return $this->equipoId;
    }

    public function distribucionCombustible(): DistribucionCombustible
    {
        return $this->distribucion;
    }

    /** @return list<ViajeConsumido> */
    public function viajes(): array
    {
        return $this->viajes;
    }

    public function cobertura(): CoberturaPeriodo
    {
        return $this->cobertura;
    }

    public function muestrasLeidas(): int
    {
        return $this->muestrasLeidas;
    }

    public function sinCalibrar(): bool
    {
        return $this->sinCalibrar;
    }

    /** @return array<string, float|int> */
    public function umbrales(): array
    {
        return $this->umbrales;
    }

    public function kilometrajeTotal(): int
    {
        $total = 0;

        foreach ($this->viajes as $viaje) {
            $total += $viaje->viaje()->kilometraje();
        }

        return $total;
    }

    public function litrosTotales(): float
    {
        $total = 0.0;

        foreach ($this->viajes as $viaje) {
            $total += $viaje->viaje()->litrosConsumidos();
        }

        return $total;
    }

    /** Viajes que se pueden atribuir a un tiempo, y por lo tanto llevar l/h. */
    public function viajesConHora(): int
    {
        $total = 0;

        foreach ($this->viajes as $viaje) {
            if ($viaje->indicadores()->litrosPorHoraMotor() !== null) {
                $total++;
            }
        }

        return $total;
    }

    /** Viajes sin medición de distancia: no se les puede calcular l/100 km. */
    public function viajesSinConsumo(): int
    {
        $total = 0;

        foreach ($this->viajes as $viaje) {
            if ($viaje->indicadores()->litrosPor100Km() === null) {
                $total++;
            }
        }

        return $total;
    }
}