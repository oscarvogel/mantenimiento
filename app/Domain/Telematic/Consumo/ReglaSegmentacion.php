<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

use App\Domain\Telematic\MuestraEquipo;

/**
 * Agrupa los intervalos en movimiento de una serie en viajes.
 *
 * El movimiento se decide por el odómetro y no por la velocidad. La velocidad
 * es un instante y el odómetro es una distancia acumulada: un equipo detenido
 * puede tener un pico de velocidad tomado justo cuando arrancó, y un proveedor que manda posición sin odómetro no
 * genera ningún viaje en lugar de inventar que el camión se movió.
 *
 * El cierre necesita dos intervalos consecutivos sin movimiento. Con uno solo,
 * cada semáforo y cada estación de servicio partirían el viaje en trozos que no
 * existen.
 *
 * Un viaje es un grupo **contiguo** de intervalos. No hay solapamiento ni
 * huecos: cada intervalo pertenece a un viaje o a ninguno.
 */
final readonly class ReglaSegmentacion
{
    public function __construct(private PoliticaSegmentacion $politica)
    {
    }

    /**
     * @param list<MuestraEquipo> $muestras Capturas de un solo equipo.
     *
     * @return list<ViajeSegmentado> Viajes ordenados por inicio.
     */
    public function segmentar(array $muestras, PoliticaAtribucion $atribucion): array
    {
        if (count($muestras) < 2) {
            return [];
        }

        usort(
            $muestras,
            static fn (MuestraEquipo $a, MuestraEquipo $b): int => $a->observadaEn() <=> $b->observadaEn(),
        );

        $viajes = [];
        $enCurso = [];
        $parados = 0;

        for ($i = 1, $total = count($muestras); $i < $total; $i++) {
            $intervalo = $this->intervalo($muestras[$i - 1], $muestras[$i], $atribucion);

            if ($intervalo === null) {
                continue;
            }

            if ($this->seMovio($intervalo)) {
                $parados = 0;
                $enCurso[] = $intervalo;

                continue;
            }

            if ($enCurso === []) {
                continue;
            }

            if (++$parados >= $this->politica->intervalosParadoParaCerrar) {
                $viaje = $this->cerrar($enCurso);

                if ($viaje !== null) {
                    $viajes[] = $viaje;
                }

                $enCurso = [];
                $parados = 0;
            }
        }

        // La serie puede terminar con el equipo todavía en ruta.
        if ($enCurso !== []) {
            $viaje = $this->cerrar($enCurso);

            if ($viaje !== null) {
                $viajes[] = $viaje;
            }
        }

        return $viajes;
    }

    private function intervalo(
        MuestraEquipo $desde,
        MuestraEquipo $hasta,
        PoliticaAtribucion $atribucion,
    ): ?IntervaloConsumo {
        try {
            return IntervaloConsumo::entre($desde, $hasta, $atribucion);
        } catch (\InvalidArgumentException) {
            // Capturas de equipos o integraciones mezcladas: no forman un
            // intervalo. Se saltean en vez de romper toda la serie.
            return null;
        }
    }

    /** Sin odómetro no se puede afirmar desplazamiento. */
    private function seMovio(IntervaloConsumo $intervalo): bool
    {
        $km = $intervalo->kilometraje();

        return $km !== null && $km > 0;
    }

    /** @param list<IntervaloConsumo> $enCurso */
    private function cerrar(array $enCurso): ?ViajeSegmentado
    {
        $viaje = new ViajeSegmentado($enCurso, $this->politica->minutosMinimosViaje);

        // El mínimo de kilómetros descarta maniobras. Sin esto, cada avance en
        // un semáforo y cada empate en una estación de servicio sería una
        // fila del reporte de consumo.
        return $viaje->kilometraje() >= $this->politica->kmMinimosViaje ? $viaje : null;
    }
}
