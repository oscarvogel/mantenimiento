<?php

declare(strict_types=1);

namespace App\Domain\Trips;

/**
 * Consumo de combustible por viaje. Devuelve null cuando faltan los datos
 * minimos: nunca inventa un rendimiento a partir de kilometros o litros en cero.
 */
final class TripConsumption
{
    /**
     * Rango de consumo razonable para un camión con semirremolque, en L/100 km.
     * Fuera de este rango el cierre avisa al operador que revise los odómetros
     * o las cargas: no bloquea, porque un viaje puede ser excepcional.
     */
    public const TYPICAL_MIN_LITERS_PER_100 = 20.0;
    public const TYPICAL_MAX_LITERS_PER_100 = 60.0;

    public static function isUnusual(?float $litersPer100): bool
    {
        return $litersPer100 !== null
            && ($litersPer100 < self::TYPICAL_MIN_LITERS_PER_100 || $litersPer100 > self::TYPICAL_MAX_LITERS_PER_100);
    }

    public static function litersPer100Km(float $liters, int $km): ?float
    {
        if ($km <= 0 || $liters <= 0.0) {
            return null;
        }

        return round($liters / $km * 100, 1);
    }
}
