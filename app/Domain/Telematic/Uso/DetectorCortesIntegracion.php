<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Uso;

use DateTimeImmutable;

/**
 * Encuentra los períodos en que una integración dejó de grabar datos.
 *
 * Un camión sin datos es un camión. La mitad de la flota de una misma
 * integración sin datos a la vez es la integración: el proveedor, la cuenta o
 * nuestra ingesta. Entre el 08/11 y el 19/11/2026 las 14 unidades Wialon no
 * grabaron nada y ninguna pantalla lo mostró.
 *
 * Un hueco corto no alcanza: Autotrac no manda nada con el camión parado
 * (Wialon sí, cada hora), y una noche con media flota en la base dejaba huecos
 * simultáneos de 6 a 8 horas. Por eso el mínimo es de 12 horas, para cada
 * hueco y para el tiempo en que coinciden.
 *
 * Para cada unidad se toman sus huecos internos y el hueco final hasta el
 * cierre del período. El hueco antes de la primera muestra no cuenta: una
 * unidad recién vinculada no estaba "cortada", todavía no existía.
 */
final readonly class DetectorCortesIntegracion
{
    public function __construct(private PoliticaUso $politica = new PoliticaUso())
    {
    }

    /**
     * @param array<int|string, list<int>> $observacionesPorUnidad Instantes (segundos Unix) con datos de cada
     *                                                            unidad de la integración. Enteros y no fechas:
     *                                                            un mes de la flota son cientos de miles.
     *
     * @return list<CorteIntegracion>
     */
    public function detectar(array $observacionesPorUnidad, DateTimeImmutable $hasta): array
    {
        $total = count($observacionesPorUnidad);
        $necesarias = max($this->politica->corteMinimoUnidades, (int) ceil($total * $this->politica->corteFraccionUnidades));
        if ($total < $necesarias) {
            return [];
        }

        $minimo = $this->politica->corteMinimoMinutos * 60;
        $fin = $hasta->getTimestamp();
        $eventos = [];

        foreach ($observacionesPorUnidad as $marcas) {
            sort($marcas);
            $marcas[] = $fin;

            for ($i = 1, $n = count($marcas); $i < $n; $i++) {
                if ($marcas[$i] - $marcas[$i - 1] >= $minimo) {
                    $eventos[] = [$marcas[$i - 1], 1];
                    $eventos[] = [$marcas[$i], -1];
                }
            }
        }

        // A igual instante, primero las salidas: un hueco que termina cuando
        // otro empieza no se cuenta dos veces.
        usort($eventos, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $cortes = [];
        $enHueco = 0;
        $abierto = null;
        $maximo = 0;
        foreach ($eventos as [$momento, $delta]) {
            $enHueco += $delta;

            if ($abierto === null && $enHueco >= $necesarias) {
                $abierto = $momento;
                $maximo = $enHueco;
            } elseif ($abierto !== null) {
                $maximo = max($maximo, $enHueco);
                if ($enHueco < $necesarias) {
                    // El solapamiento también tiene que durar el mínimo: tres
                    // huecos largos que coinciden una hora no son un corte.
                    if ($momento - $abierto >= $minimo) {
                        $cortes[] = $this->corte($abierto, $momento, $maximo, $total, $momento >= $fin, $hasta);
                    }
                    $abierto = null;
                }
            }
        }

        if ($abierto !== null && $fin - $abierto >= $minimo) {
            $cortes[] = $this->corte($abierto, $fin, $maximo, $total, true, $hasta);
        }

        return $cortes;
    }

    private function corte(int $desde, int $hasta, int $afectadas, int $total, bool $abierto, DateTimeImmutable $referencia): CorteIntegracion
    {
        $zona = $referencia->getTimezone();

        return new CorteIntegracion(
            (new DateTimeImmutable('@' . $desde))->setTimezone($zona),
            (new DateTimeImmutable('@' . $hasta))->setTimezone($zona),
            $afectadas,
            $total,
            $abierto,
        );
    }
}
