<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

/**
 * Odómetro del equipo según su telemetría, para sugerir el km de regreso al
 * cerrar un viaje.
 *
 * Es de solo lectura y no reemplaza la lectura del tablero: el operador la
 * confirma o la corrige al cerrar. Devuelve null si el equipo no tiene
 * telemetría con kilometraje.
 */
interface TripOdometerTelemetry
{
    /**
     * Última lectura de kilometraje observada hasta `$untilUtc` (inclusive).
     * Con `$untilUtc` en null, la última lectura disponible.
     *
     * @return array{km:int, observed_at:string, provider:string}|null
     */
    public function odometerAt(int $companyId, int $equipmentId, ?string $untilUtc): ?array;
}
