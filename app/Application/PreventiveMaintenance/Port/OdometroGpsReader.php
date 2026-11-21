<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance\Port;

use App\Domain\PreventiveMaintenance\Gps\OdometroGps;
use DateTimeImmutable;

/**
 * Valores del odómetro del GPS de un equipo en una ventana.
 *
 * Puerto del consumidor: lo usa Preventivo para estimar el km actual y lo
 * responde Telemetría. Devuelve solo los valores con odómetro; un equipo sin
 * telemetría o sin odómetro devuelve una lista vacía.
 */
interface OdometroGpsReader
{
    /**
     * @param int $empresaId Obligatorio: la serie de telemetría se recorta por empresa.
     *
     * @return list<OdometroGps>
     */
    public function odometros(int $empresaId, int $equipoId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array;
}
