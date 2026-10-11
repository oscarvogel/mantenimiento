<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

use InvalidArgumentException;

/**
 * Umbrales con los que se decide qué es consumo, qué es carga y qué es ruido.
 *
 * Son por empresa y configurables, no constantes del código, porque dependen
 * del equipo de combustible instalado: un tanque de 780 litros con dos
 * entradas analógicas fluctúa distinto que uno de_predictos.
 *
 * El umbral de carga tiene que ser positivo y mayor que el delta mínimo. Si
 * no, no hay forma de decidir, y decidir por omisión sería asumir que todo
 * descenso es consumo: que es exactamente el supuesto que rompe el cálculo
 * cuando el camión carga a mitad de camino.
 */
final readonly class PoliticaAtribucion
{
    public function __construct(
        public float $deltaMinimoLitros,
        public float $umbralCargaLitros,
        public int $huecoMaximoMinutos = 30,
    ) {
        if ($this->deltaMinimoLitros < 0) {
            throw new InvalidArgumentException('El delta mínimo de litros no puede ser negativo.');
        }

        if ($this->umbralCargaLitros <= 0) {
            throw new InvalidArgumentException('El umbral de carga tiene que ser positivo: sin él no se puede separar una carga de un consumo.');
        }

        if ($this->umbralCargaLitros <= $this->deltaMinimoLitros) {
            throw new InvalidArgumentException('El umbral de carga tiene que ser mayor que el delta mínimo.');
        }

        if ($this->huecoMaximoMinutos <= 0) {
            throw new InvalidArgumentException('El hueco máximo tiene que ser positivo.');
        }
    }
}