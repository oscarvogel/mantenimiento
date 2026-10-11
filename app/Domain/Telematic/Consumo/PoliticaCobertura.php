<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Decide si un período tiene suficientes datos para mostrar un indicador.
 *
 * Responde una pregunta distinta de la del intervalo. El intervalo pregunta si
 * un hueco lo vuelve inservible; esta política pregunta cuánto del rango que
 * el operador pidió mirar está realmente respaldado por capturas.
 *
 * Los dos umbrales son configurables y se complementan:
 *
 * - **Cobertura mínima.** El caso que la justifica es la cola: pedir ocho horas
 *   y que el equipo lleve tres sin reportar deja cinco horas de datos buenos y
 *   tres de ausencia. Mostrar el consumo de las cinco sin decirlo hace que un
 *   equipo que dejó de reportar parezca uno que consumió poco.
 *
 * - **Hueco máximo.** Setenta por ciento de cobertura con dos horas perdidas en
 *   un tramo no es un período confiable, porque ese tramo es justo donde el
 *   camión pudo haber cargado. Promediarlo esconde lo importante.
 */
final readonly class PoliticaCobertura
{
    public function __construct(
        public float $coberturaMinima = 0.70,
        public int $huecoMaximoMinutos = 30,
    ) {
        if ($this->coberturaMinima <= 0.0 || $this->coberturaMinima > 1.0) {
            throw new InvalidArgumentException('La cobertura mínima tiene que ser una proporción entre 0 y 1.');
        }

        if ($this->huecoMaximoMinutos <= 0) {
            throw new InvalidArgumentException('El hueco máximo tiene que ser positivo.');
        }
    }

    /**
     * @param list<IntervaloConsumo> $intervalos Ya ordenados y recortables.
     */
    public function evaluar(
        array $intervalos,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
    ): CoberturaPeriodo {
        $esperados = intdiv($hasta->getTimestamp() - $desde->getTimestamp(), 60);
        $cubiertos = 0;
        $huecos = [];
        $cursor = $desde;

        foreach ($intervalos as $intervalo) {
            $inicio = max($intervalo->desde(), $desde);
            $fin = min($intervalo->hasta(), $hasta);

            // Intervalo completamente afuera del período pedido.
            if ($inicio >= $fin) {
                continue;
            }

            $hueco = intdiv($inicio->getTimestamp() - $cursor->getTimestamp(), 60);

            if ($hueco > 0) {
                $huecos[] = $hueco;
            }

            $duracion = intdiv($fin->getTimestamp() - $inicio->getTimestamp(), 60);

            if ($intervalo->consumoUtilizable()) {
                $cubiertos += $duracion;
            } else {
                // El intervalo en sí es un hueco: abarca más tiempo del que se
                // puede asumir que el equipo siguió reportando. Contarlo como
                // cubierto haría que una cobertura del 100 % dijera que no
                // falta nada, cuando en realidad hay dos horas sin datos.
                $huecos[] = $duracion;
            }

            $cursor = $fin;
        }

        // La cola: el operador pidió hasta acá y el equipo no reportó más.
        $cola = intdiv($hasta->getTimestamp() - $cursor->getTimestamp(), 60);

        if ($cola > 0) {
            $huecos[] = $cola;
        }

        return new CoberturaPeriodo(
            $esperados,
            $cubiertos,
            $huecos,
            $this->coberturaMinima,
            $this->huecoMaximoMinutos,
        );
    }
}