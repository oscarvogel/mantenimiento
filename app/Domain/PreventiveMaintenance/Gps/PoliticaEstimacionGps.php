<?php

declare(strict_types=1);

namespace App\Domain\PreventiveMaintenance\Gps;

use InvalidArgumentException;

/**
 * Umbrales para estimar el km actual con el GPS.
 *
 * - **6 h** de tolerancia para anclar: el valor del GPS que corresponde a la
 *   lectura del chofer es el más cercano dentro de 6 h; a igual distancia, el
 *   anterior. Más lejos, el camión pudo haber hecho cientos de km entre uno y
 *   otro y la estimación arrastraría ese error. El mismo umbral que usan la
 *   conciliación de combustible y la actividad de la flota para un corte.
 * - **120 km/h de velocidad media** como tope de plausibilidad: un camión
 *   gobernado cerca de 86 km/h no promedia más entre dos instantes. Por encima,
 *   el odómetro del GPS saltó y no se estima.
 * - **60 días** de antigüedad máxima de la lectura: con una lectura más vieja
 *   el problema es la falta de lecturas, no algo que el GPS deba tapar.
 */
final readonly class PoliticaEstimacionGps
{
    public function __construct(
        public int $toleranciaAnclaMinutos = 360,
        public float $velocidadMediaMaximaKmh = 120.0,
        public int $antiguedadMaximaLecturaDias = 60,
    ) {
        if ($toleranciaAnclaMinutos <= 0 || $antiguedadMaximaLecturaDias <= 0) {
            throw new InvalidArgumentException('Las ventanas de tiempo tienen que ser positivas.');
        }

        if ($velocidadMediaMaximaKmh <= 0.0) {
            throw new InvalidArgumentException('La velocidad media máxima tiene que ser positiva.');
        }
    }
}
