<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Uso;

use InvalidArgumentException;

/**
 * Umbrales del uso de la flota y del estado de la telemetría.
 *
 * Calibrados con 40 días de la flota de TSA (12/10 al 20/11/2026, ver
 * `docs/BITACORA_2026-11-20_USO_FLOTA_TELEMETRIA.md`):
 *
 * - **30 min** de hueco máximo para repartir tiempo entre dos muestras: el 90%
 *   de las muestras llega a menos de 60 min de la anterior, y un tramo más
 *   largo no dice qué hizo el camión en el medio.
 * - **90 km/h en dos muestras seguidas** es exceso: los camiones circulan
 *   gobernados cerca de 86 km/h, y una sola muestra alta puede ser un salto
 *   del GPS.
 * - **Un corte de la integración** es un hueco de 12 h o más en la mitad o más
 *   de sus unidades a la vez. Uno solo es un camión; muchos a la vez, el
 *   proveedor o nuestra ingesta. Con 6 h, las noches de Autotrac (que no
 *   reporta con el camión parado) aparecían como cortes.
 * - **24 h sin reportar** con la integración funcionando es un camión que hay
 *   que mirar.
 */
final readonly class PoliticaUso
{
    public function __construct(
        public int $huecoMaximoMinutos = 30,
        public float $velocidadExcesoKmh = 90.0,
        public int $muestrasExceso = 2,
        public float $velocidadDetenidoKmh = 3.0,
        public int $minutosMinimosMotor = 60,
        public float $coberturaRalentiMinima = 0.8,
        public int $corteMinimoMinutos = 720,
        public float $corteFraccionUnidades = 0.5,
        public int $corteMinimoUnidades = 2,
        public int $sinReportarMinutos = 1440,
    ) {
        if ($huecoMaximoMinutos <= 0 || $corteMinimoMinutos <= 0 || $sinReportarMinutos <= 0) {
            throw new InvalidArgumentException('Las ventanas de tiempo tienen que ser positivas.');
        }

        if ($velocidadExcesoKmh <= 0.0 || $muestrasExceso < 1) {
            throw new InvalidArgumentException('El exceso de velocidad necesita un umbral y al menos una muestra.');
        }

        if ($corteFraccionUnidades <= 0.0 || $corteFraccionUnidades > 1.0 || $corteMinimoUnidades < 1) {
            throw new InvalidArgumentException('Un corte necesita una fracción entre 0 y 1 y al menos una unidad.');
        }

        if ($coberturaRalentiMinima <= 0.0 || $coberturaRalentiMinima > 1.0) {
            throw new InvalidArgumentException('La cobertura del ralentí va entre 0 y 1.');
        }
    }
}
