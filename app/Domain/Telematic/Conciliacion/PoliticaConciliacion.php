<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Conciliacion;

use InvalidArgumentException;

/**
 * Umbrales de la conciliación entre lo que declara el chofer y lo que mide el
 * sensor del tanque.
 *
 * Los valores por defecto salen del análisis de 40 días de la flota de TSA
 * (12/10 al 20/11/2026, ver `docs/BITACORA_2026-11-20_CONCILIACION_COMBUSTIBLE.md`):
 *
 * - **60 L** de umbral de carga: las cargas reales vistas van de 60 a 725 L;
 *   por debajo, el ruido del sensor y el movimiento del gasoil en el tanque
 *   producen subidas falsas.
 * - **6 h** de ventana: una carga de varios surtidores o con el camión
 *   moviéndose entre muestras puede repartirse en varias capturas.
 * - **Confirmación entre 4 y 8 h después**: el sensor puede subir decenas de
 *   litros con el camión parado y volver solo (HWW968 el 25/10: 276 → 337 →
 *   277 L sin moverse). Una carga real deja el nivel arriba.
 * - **15 %** de tolerancia: el nivel del tanque y el surtidor no miden igual.
 * - **6 h** de hueco máximo: en un hueco más largo puede haber una carga que
 *   no se vio, y entonces la comparación culparía al chofer por un corte del
 *   GPS.
 */
final readonly class PoliticaConciliacion
{
    public function __construct(
        public float $umbralCargaLitros = 60.0,
        public int $ventanaCargaMinutos = 360,
        public int $confirmacionDesdeMinutos = 240,
        public int $confirmacionHastaMinutos = 480,
        public float $fraccionConfirmada = 0.8,
        public int $kmMovimientoConfirmacion = 20,
        public float $toleranciaPorcentaje = 15.0,
        public int $huecoMaximoMinutos = 360,
        public float $coberturaSensorMinima = 0.8,
        public int $margenExtremosMinutos = 360,
        public int $kmMinimosConsumo = 100,
    ) {
        if ($umbralCargaLitros <= 0.0) {
            throw new InvalidArgumentException('El umbral de carga tiene que ser positivo.');
        }

        if ($ventanaCargaMinutos <= 0 || $huecoMaximoMinutos <= 0 || $margenExtremosMinutos <= 0) {
            throw new InvalidArgumentException('Las ventanas de tiempo tienen que ser positivas.');
        }

        if ($confirmacionHastaMinutos <= $confirmacionDesdeMinutos) {
            throw new InvalidArgumentException('La ventana de confirmación tiene que tener duración.');
        }

        if ($fraccionConfirmada <= 0.0 || $fraccionConfirmada > 1.0) {
            throw new InvalidArgumentException('La fracción confirmada va entre 0 y 1.');
        }

        if ($coberturaSensorMinima <= 0.0 || $coberturaSensorMinima > 1.0) {
            throw new InvalidArgumentException('La cobertura mínima del sensor va entre 0 y 1.');
        }

        if ($toleranciaPorcentaje < 0.0) {
            throw new InvalidArgumentException('La tolerancia no puede ser negativa.');
        }
    }
}
