<?php

declare(strict_types=1);

namespace App\Domain\Trips;

/**
 * Odómetro de regreso que se le sugiere al operador al cerrar un viaje.
 *
 * Orden de preferencia:
 *   1. La telemetría del tractor hasta el fin del recorrido: es la lectura
 *      del día en que volvió, aunque el camión haya seguido andando después.
 *   2. La última lectura conocida del equipo.
 *
 * Solo se sugiere una lectura mayor que el odómetro de salida: una menor o
 * igual no es un regreso posible y no se propone. La sugerencia siempre se
 * confirma o corrige contra el tablero.
 */
final class TripReturnOdometer
{
    public const TELEMETRIA = 'TELEMETRIA';
    public const EQUIPO = 'EQUIPO';

    /**
     * @param array{km:int, observed_at:string, provider:string}|null $telemetry
     *
     * @return array{km:int, source:string, observed_at:?string, provider:?string}|null
     */
    public static function suggest(?int $departureKm, ?int $equipmentKm, ?array $telemetry): ?array
    {
        if ($telemetry !== null && self::isAfterDeparture($telemetry['km'], $departureKm)) {
            return [
                'km' => $telemetry['km'],
                'source' => self::TELEMETRIA,
                'observed_at' => $telemetry['observed_at'],
                'provider' => $telemetry['provider'] !== '' ? $telemetry['provider'] : null,
            ];
        }
        if ($equipmentKm !== null && self::isAfterDeparture($equipmentKm, $departureKm)) {
            return ['km' => $equipmentKm, 'source' => self::EQUIPO, 'observed_at' => null, 'provider' => null];
        }

        return null;
    }

    private static function isAfterDeparture(int $km, ?int $departureKm): bool
    {
        return $km > 0 && ($departureKm === null || $km > $departureKm);
    }
}
