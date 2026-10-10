<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

/**
 * Qué pasó con el combustible entre dos capturas.
 *
 * `SinVariacion` no es "no consumió": es que el cambio está por debajo del ruido
 * del sensor o no se puede atribuir. Es deliberadamente distinto de un cero,
 * porque un cero es una medición y esto no lo es.
 */
enum ClasificacionIntervalo: string
{
    case Consumo = 'CONSUMO';
    case Carga = 'CARGA';
    case SinVariacion = 'SIN_VARIACION';

    /** Ninguna de las dos capturas trae sensor de combustible. */
    case SinCombustible = 'SIN_COMBUSTIBLE';

    public function aportaConsumo(): bool
    {
        return $this === self::Consumo;
    }

    public function traduccible(): string
    {
        return match ($this) {
            self::Consumo => 'Consumo',
            self::Carga => 'Carga',
            self::SinVariacion => 'Sin variación (dentro del ruido del sensor)',
            self::SinCombustible => 'Sin sensor de combustible',
        };
    }
}