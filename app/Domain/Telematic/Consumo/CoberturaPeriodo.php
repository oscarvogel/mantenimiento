<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

/**
 * Lo que sabemos de un equipo durante un período.
 *
 * La cobertura no mide rendimiento: mide **cuánto del período pedido realmente
 * sabemos**. Son preguntas distintas y no se sustituyen.
 *
 * El caso que la hace necesaria: el operador pide "consumo de las últimas ocho
 * horas" y el equipo lleva tres sin reportar. Cinco horas de datos buenos y
 * tres de ausencia. Mostrar el consumo de las cinco sin decir nada de las tres
 * hace que un equipo que dejó de reportar parezca uno que consumió poco.
 */
final readonly class CoberturaPeriodo
{
    /**
     * @param list<int> $huecosMinutos
     */
    public function __construct(
        private int $minutosEsperados,
        private int $minutosCubiertos,
        private array $huecosMinutos,
        private float $coberturaMinima,
        private int $huecoMaximoMinutos,
    ) {
    }

    public function minutosEsperados(): int
    {
        return $this->minutosEsperados;
    }

    public function minutosCubiertos(): int
    {
        return $this->minutosCubiertos;
    }

    /** @return list<int> */
    public function huecosMinutos(): array
    {
        return $this->huecosMinutos;
    }

    /** Proporción entre 0 y 1. */
    public function cobertura(): float
    {
        return $this->minutosEsperados <= 0
            ? 0.0
            : $this->minutosCubiertos / $this->minutosEsperados;
    }

    public function hayHuecoGrande(): bool
    {
        foreach ($this->huecosMinutos as $minutos) {
            if ($minutos > $this->huecoMaximoMinutos) {
                return true;
            }
        }

        return false;
    }

    /**
     * Alcanza para mostrar un indicador del período.
     *
     * Son dos condiciones y no una: una proporción alta no tapa un hueco grande
     * en el medio. Setenta por ciento de cobertura con dos horas perdidas en un
     * tramo no es un período confiable, y promediarlo esconde
     * justamente el tramo donde el camión pudo haber cargado.
     */
    public function suficiente(): bool
    {
        return ! $this->hayHuecoGrande() && $this->cobertura() >= $this->coberturaMinima;
    }

    public function motivoInsuficiente(): ?string
    {
        if ($this->suficiente()) {
            return null;
        }

        if ($this->minutosCubiertos === 0) {
            return 'Sin datos: el proveedor no reportó ninguna captura en el período.';
        }

        $porcentaje = (int) ($this->cobertura() * 100);

        if ($this->hayHuecoGrande()) {
            $mayor = max($this->huecosMinutos);

            return sprintf(
                'Cobertura del %d%% pero con un hueco de %d minutos: en ese tramo el consumo no se puede atribuir.',
                $porcentaje,
                $mayor,
            );
        }

        return sprintf(
            'Cobertura del %d%%, por debajo del %d%% necesario. Datos insuficientes para el período.',
            $porcentaje,
            (int) ($this->coberturaMinima * 100),
        );
    }
}