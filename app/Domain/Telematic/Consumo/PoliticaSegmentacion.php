<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

use InvalidArgumentException;

/**
 * Con qué reglas se decide qué es un viaje.
 *
 * Los tres umbrales existen por lo que se ve, no por lo que se supone:
 *
 * - **Kilómetros mínimos.** Sin esto, cada maniobra de estacionamiento es una
 *   fila del reporte. El ruido del odómetro puede generar pequeños intervalos
 *   aunque el equipo no haya hecho un viaje.
 *
 * - **Minutos mínimos.** Un trayecto más corto que los intervalos observados
 *   puede quedar con cero o una muestra. No se puede reconstruir y no se estima.
 *
 * - **Intervalos parados para cerrar.** Con un solo intervalo sin movimiento
 *   se cerraría el viaje en cada cruce, en cada semáforo y en cada station de
 *   servicio. Con dos consecutivos, el cierre significa que el equipo se detuvo.
 *
 * **El movimiento se decide por el odómetro, no por la velocidad.** La
 * velocidad es un instante; el odómetro es una distancia acumulada. Un equipo
 * detenido puede tener un pico de velocidad tomado justo cuando arrancó. Y un proveedor que manda posición sin
 * odómetro no genera viajes: no se puede afirmar que se movió.
 */
final readonly class PoliticaSegmentacion
{
    public function __construct(
        public int $kmMinimosViaje = 1,
        public int $minutosMinimosViaje = 5,
        public int $intervalosParadoParaCerrar = 2,
    ) {
        if ($this->kmMinimosViaje < 0) {
            throw new InvalidArgumentException('Los kilómetros mínimos no pueden ser negativos.');
        }

        if ($this->minutosMinimosViaje <= 0) {
            throw new InvalidArgumentException('Los minutos mínimos tienen que ser positivos.');
        }

        if ($this->intervalosParadoParaCerrar <= 0) {
            throw new InvalidArgumentException('Hace falta al menos un intervalo para cerrar un viaje.');
        }
    }
}
