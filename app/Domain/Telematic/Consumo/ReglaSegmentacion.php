<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

/**
 * Agrupa los intervalos en movimiento de una serie en viajes.
 *
 * Recibe una `SerieConsumo` y no capturas crudas, a propósito. Ordenar las
 * capturas y decidir qué intervalos existen son cosas que hace `SerieConsumo`, y
 * que dos clases tengan su propia versión de esa regla es la forma más corta
 * de que un día una calcule distinto de la otra.
 *
 * El movimiento se decide por el odómetro y no por la velocidad. La velocidad
 * es un instante y el odómetro es una distancia acumulada: con capturas cada
 * quince minutos, un equipo detenido puede tener un pico de velocidad tomado
 * justo cuando arrancó, y un proveedor que manda posición sin odómetro no
 * genera ningún viaje en lugar de inventar que el camión se movió.
 *
 * El cierre necesita dos intervalos consecutivos sin movimiento. Con uno solo,
 * cada semáforo y cada estación de servicio partirían la rodada en trozos que
 * no existen.
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
     * @return list<ViajeSegmentado> Viajes ordenados por inicio.
     */
    public function segmentar(SerieConsumo $serie): array
    {
        $viajes = [];
        $enCurso = [];
        $parados = 0;

        foreach ($serie->intervalos() as $intervalo) {
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