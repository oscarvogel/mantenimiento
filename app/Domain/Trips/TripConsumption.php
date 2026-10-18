<?php

declare(strict_types=1);

namespace App\Domain\Trips;

/**
 * Consumo de combustible por viaje. Devuelve null cuando faltan los datos
 * minimos: nunca inventa un rendimiento a partir de kilometros o litros en cero.
 */
final class TripConsumption
{
    public static function litersPer100Km(float $liters, int $km): ?float
    {
        if ($km <= 0 || $liters <= 0.0) {
            return null;
        }

        return round($liters / $km * 100, 1);
    }
}
