<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Conciliacion;

use App\Domain\Telematic\MuestraEquipo;

/**
 * Encuentra las cargas de combustible en la serie del sensor del tanque.
 *
 * **No compara muestra contra muestra.** Ese es el criterio de
 * `IntervaloConsumo`, que sirve para repartir consumo en el tiempo pero no para
 * contar cargas: una carga hecha en dos surtidores, o con el camión moviéndose
 * entre capturas, queda partida en escalones que no llegan solos al umbral, y
 * una subida falsa del sensor en una sola captura cuenta como carga.
 *
 * El criterio acá es de nivel:
 *
 * 1. El nivel "antes" es la mediana de hasta tres lecturas previas. La mediana
 *    descarta una lectura suelta mala sin necesitar un umbral de ruido.
 * 2. Se busca, dentro de la ventana, el **pico** de la mediana de tres
 *    lecturas que supera al nivel previo en el umbral. El pico junta los
 *    escalones de una misma carga, y no se deja engañar por el consumo de un
 *    camión que cargó y salió a la ruta: tomar la última posición de la
 *    ventana subestimaba la carga en lo que el camión ya había gastado, y eso
 *    hacía parecer que el chofer declaraba de más.
 * 3. Si el camión no se movió, el nivel tiene que **seguir arriba** unas horas
 *    después. El sensor puede subir decenas de litros con el camión parado y
 *    volver solo, y eso no es una carga.
 *
 * Una serie sin lecturas de combustible no tiene cargas: no se puede afirmar
 * que no las hubo, pero eso lo decide la conciliación, que mira la cobertura.
 */
final readonly class DetectorCargasCombustible
{
    public function __construct(private PoliticaConciliacion $politica = new PoliticaConciliacion())
    {
    }

    /**
     * @param list<MuestraEquipo> $muestras En orden ascendente de `observadaEn`.
     *
     * @return list<CargaDetectada>
     */
    public function detectar(array $muestras): array
    {
        $serie = array_values(array_filter(
            $muestras,
            static fn (MuestraEquipo $muestra): bool => $muestra->combustibleLitros() !== null,
        ));
        $total = count($serie);
        $cargas = [];
        $ventana = $this->politica->ventanaCargaMinutos * 60;

        $i = 1;
        while ($i < $total) {
            $antes = $this->mediana($this->niveles($serie, max(0, $i - 3), $i));
            $limite = $serie[$i - 1]->observadaEn()->getTimestamp() + $ventana;
            $hallazgo = null;

            for ($j = $i; $j < $total && $serie[$j]->observadaEn()->getTimestamp() <= $limite; $j++) {
                $despues = $this->mediana($this->niveles($serie, $j, min($total, $j + 3)));
                if ($despues - $antes >= $this->politica->umbralCargaLitros
                    && ($hallazgo === null || $despues > $hallazgo[1])) {
                    $hallazgo = [$j, $despues];
                }
            }

            if ($hallazgo === null) {
                $i++;
                continue;
            }

            [$j, $despues] = $hallazgo;
            if (! $this->seSostiene($serie, $j, $antes, $despues)) {
                $i++;
                continue;
            }

            // El inicio es la última lectura antes de que el nivel suba la mitad
            // de la carga: la ventana puede haber alcanzado la subida desde
            // horas antes, y esa hora no es la de la carga.
            $inicio = $i - 1;
            $mitad = $antes + ($despues - $antes) / 2;
            for ($k = $i; $k <= $j; $k++) {
                if ((float) $serie[$k]->combustibleLitros() >= $mitad) {
                    break;
                }
                $inicio = $k;
            }

            $cargas[] = new CargaDetectada(
                $serie[$inicio]->observadaEn(),
                $serie[$j]->observadaEn(),
                $antes,
                $despues,
            );
            $i = $j + 3;
        }

        return $cargas;
    }

    /**
     * Una carga real deja el tanque arriba. Si el camión salió a la ruta en el
     * medio, el consumo baja el nivel y la comprobación no aplica.
     *
     * @param list<MuestraEquipo> $serie
     */
    private function seSostiene(array $serie, int $j, float $antes, float $despues): bool
    {
        $fin = $serie[$j];
        $desde = $fin->observadaEn()->getTimestamp() + $this->politica->confirmacionDesdeMinutos * 60;
        $hasta = $fin->observadaEn()->getTimestamp() + $this->politica->confirmacionHastaMinutos * 60;

        $posteriores = array_values(array_filter(
            $serie,
            static fn (MuestraEquipo $m): bool => $m->observadaEn()->getTimestamp() >= $desde
                && $m->observadaEn()->getTimestamp() <= $hasta,
        ));

        if ($posteriores === []) {
            // Sin lecturas después no hay con qué desmentirla.
            return true;
        }

        $kmFin = $fin->kilometraje();
        foreach ($posteriores as $posterior) {
            $km = $posterior->kilometraje();
            if ($kmFin !== null && $km !== null && $km - $kmFin > $this->politica->kmMovimientoConfirmacion) {
                return true;
            }
        }

        $nivel = $this->mediana(array_map(
            static fn (MuestraEquipo $m): float => (float) $m->combustibleLitros(),
            $posteriores,
        ));

        return $nivel >= $antes + $this->politica->fraccionConfirmada * ($despues - $antes);
    }

    /**
     * @param list<MuestraEquipo> $serie
     *
     * @return list<float>
     */
    private function niveles(array $serie, int $desde, int $hasta): array
    {
        $niveles = [];
        for ($k = $desde; $k < $hasta; $k++) {
            $niveles[] = (float) $serie[$k]->combustibleLitros();
        }

        return $niveles;
    }

    /** @param list<float> $valores */
    private function mediana(array $valores): float
    {
        sort($valores);
        $n = count($valores);
        $medio = intdiv($n, 2);

        return $n % 2 === 1 ? $valores[$medio] : ($valores[$medio - 1] + $valores[$medio]) / 2;
    }
}
