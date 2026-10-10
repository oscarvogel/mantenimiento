<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

use DateTimeImmutable;

/**
 * Un trayecto derivado de una serie de intervalos.
 *
 * No se persiste. Se recalcula en cada lectura porque es una hipótesis sobre la
 * serie, y una hipótesis que queda guardada deja de actualizarse cuando la
 * cobertura mejora.
 *
 * Sabe cuándo no sabe: `reconstruible()` es falso cuando el viaje quedó
 * contenido en un solo intervalo en movimiento, porque en ese caso no se sabe
 * ni cuándo empezó ni cuándo terminó. Un viaje de ocho minutos con muestreo
 * cada quince no aparece, y eso es preferible a mostrar una fila con horas
 * inventadas.
 */
final readonly class ViajeSegmentado
{
    /**
     * @param list<IntervaloConsumo> $intervalos Intervalos en movimiento, contiguos.
     * @param int                    $minutosMinimos
     */
    public function __construct(
        private array $intervalos,
        private int $minutosMinimos,
    ) {
    }

    /** @return list<IntervaloConsumo> */
    public function intervalos(): array
    {
        return $this->intervalos;
    }

    public function inicio(): DateTimeImmutable
    {
        return $this->intervalos[0]->desde();
    }

    public function fin(): DateTimeImmutable
    {
        return $this->intervalos[count($this->intervalos) - 1]->hasta();
    }

    public function duracionMinutos(): int
    {
        return intdiv(
            $this->fin()->getTimestamp() - $this->inicio()->getTimestamp(),
            60,
        );
    }

    public function kilometraje(): int
    {
        $total = 0;

        foreach ($this->intervalos as $intervalo) {
            $total += $intervalo->kilometraje() ?? 0;
        }

        return $total;
    }

    public function litrosConsumidos(): float
    {
        $total = 0.0;

        foreach ($this->intervalos as $intervalo) {
            $total += $intervalo->litros() ?? 0.0;
        }

        return $total;
    }

    public function litrosCargados(): float
    {
        $total = 0.0;

        foreach ($this->intervalos as $intervalo) {
            $total += $intervalo->litrosCargados() ?? 0.0;
        }

        return $total;
    }

    public function huboCarga(): bool
    {
        foreach ($this->intervalos as $intervalo) {
            if ($intervalo->huboCarga()) {
                return true;
            }
        }

        return false;
    }

    /** Algún intervalo quedó con una subida que podría ser una transferencia. */
    public function tieneTransferenciaPosible(): bool
    {
        foreach ($this->intervalos as $intervalo) {
            if ($intervalo->puedeHaberTransferencia()) {
                return true;
            }
        }

        return false;
    }

    /** Minutos que se pueden atribuir: los de los intervalos sin hueco. */
    public function minutosUtilizables(): int
    {
        $total = 0;

        foreach ($this->intervalos as $intervalo) {
            if ($intervalo->consumoUtilizable()) {
                $total += $intervalo->duracionMinutos();
            }
        }

        return $total;
    }

    /**
     * Todos los intervalos completos. Con un hueco, los litros y los kilómetros
     * siguen valiendo, pero no se les puede atribuir un tiempo, así que los
     * indicadores por hora no son calculables.
     */
    public function consumoConfiable(): bool
    {
        foreach ($this->intervalos as $intervalo) {
            if (! $intervalo->consumoUtilizable()) {
                return false;
            }
        }

        return $this->reconstruible();
    }

    /**
     * Con un solo intervalo en movimiento no se sabe dónde arrancó ni dónde
     * terminó el trayecto.
     */
    public function reconstruible(): bool
    {
        return count($this->intervalos) >= 2 && $this->duracionMinutos() >= $this->minutosMinimos;
    }

    public function motivoNoReconstruible(): ?string
    {
        if ($this->reconstruible()) {
            return null;
        }

        if (count($this->intervalos) < 2) {
            return 'El viaje no se puede reconstruir: quedó en una sola captura en movimiento, '
                . 'así que no se sabe cuándo empezó ni cuándo terminó.';
        }

        return 'El viaje no se puede reconstruir: duró menos que el mínimo configurado.';
    }
}