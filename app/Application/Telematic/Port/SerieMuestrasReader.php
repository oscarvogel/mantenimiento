<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

use App\Domain\Telematic\MuestraEquipo;
use DateTimeImmutable;

/**
 * Lee la serie de capturas de un equipo en una ventana temporal.
 *
 * Es el único acceso al histórico para el consumo por viaje, el porcentaje de
 * ralentí y el exceso de velocidad. Los tres son cálculos sobre diferencias
 * entre capturas consecutivas, así que **el orden importa**: una serie sin
 * ordenar produce consumos negativos y duraciones imposibles en silencio. Por
 * eso la lista devuelta va siempre en orden ascendente de `observadaEn`.
 *
 * La empresa es el primer parámetro y no es opcional. El scoping por
 * `empresa_id` es una invariante de seguridad, no un filtro de presentación: una
 * lectura de histórico sin empresa devolvería datos de otro cliente, y en este
 * caso además datos crudos de posición.
 */
interface SerieMuestrasReader
{
    /**
     * @param int             $empresaId Empresa del actor. Obligatorio.
     * @param int             $equipoId  Equipo cuyo histórico se lee.
     * @param DateTimeImmutable $desde   Inicio de la ventana, inclusive.
     * @param DateTimeImmutable $hasta   Fin de la ventana, inclusive.
     *
     * @return list<MuestraEquipo> Capturas ordenadas ascendentemente por `observadaEn`.
     */
    public function serie(
        int $empresaId,
        int $equipoId,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
    ): array;
}