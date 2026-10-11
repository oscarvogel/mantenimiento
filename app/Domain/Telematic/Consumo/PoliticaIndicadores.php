<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

use InvalidArgumentException;

/**
 * Qué tan rápido puede moverse un camión mientras el motor está encendido.
 *
 * La banda existe por una observación de producción: hay unidades cuyo
 * contador de horas está corrido. Una de ellas reportaba 1.038.298 km con
 * 3.726,9 h, que da **278 km/h de media**. Ningún camión recorre eso con el
 * motor encendido: el tope físico ronda los 120 km/h y el piso, con el motor
 * prendido, está unos pocos km/h.
 *
 * Cuando la velocidad implícita cae fuera de la banda, el problema no es el
 * viaje: son las horas. Por eso el indicador por hora se descarta y el resto se
 * conserva.
 *
 * Son valores y no constantes porque dependen de la flota: una flota urbana con muchas paradas tiene una media mucho más baja que una de carga
 * larga, y el piso tiene que poder bajar sin invalidar a todos.
 */
final readonly class PoliticaIndicadores
{
    public function __construct(
        public float $velocidadMaximaPlausibleKmh = 120.0,
        public float $velocidadMinimaPlausibleKmh = 3.0,
    ) {
        if ($this->velocidadMaximaPlausibleKmh <= $this->velocidadMinimaPlausibleKmh) {
            throw new InvalidArgumentException('La banda de velocidad tiene que tener un tope por encima del piso.');
        }

        if ($this->velocidadMinimaPlausibleKmh <= 0.0) {
            throw new InvalidArgumentException('El piso de velocidad tiene que ser positivo.');
        }
    }

    public function esPlausible(float $velocidadKmh): bool
    {
        return $velocidadKmh >= $this->velocidadMinimaPlausibleKmh
            && $velocidadKmh <= $this->velocidadMaximaPlausibleKmh;
    }
}