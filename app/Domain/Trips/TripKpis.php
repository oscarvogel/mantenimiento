<?php

declare(strict_types=1);

namespace App\Domain\Trips;

/**
 * Indicadores (KPI) de un viaje para su resumen.
 *
 * Regla: un indicador que no se puede calcular con datos reales queda en
 * null y se informa como "Sin dato". Nunca se divide por cero ni se completa
 * un valor faltante con una estimación.
 */
final class TripKpis
{
    /**
     * @param int|null   $km              km recorridos (regreso - salida, o estimados por cargas)
     * @param float      $liters          litros cargados en el viaje
     * @param float      $fuelAmount      importe del combustible con precio, en ARS
     * @param float|null $invoice         facturación total; null si algún tramo no la tiene
     * @param float|null $operatingCost   costo operativo total; null si falta la comisión
     * @param float|null $margin          margen operativo; null si no se puede calcular
     * @param float|null $tons            toneladas transportadas; null si ningún tramo las tiene
     * @param int|null   $days            días entre salida y regreso (o hoy, si sigue en curso)
     *
     * @return array<string,float|int|null>
     */
    public static function calculate(
        ?int $km,
        float $liters,
        float $fuelAmount,
        ?float $invoice,
        ?float $operatingCost,
        ?float $margin,
        ?float $tons,
        ?int $days,
    ): array {
        $hasKm = $km !== null && $km > 0;
        $hasLiters = $liters > 0.0;

        return [
            'km' => $hasKm ? $km : null,
            'days' => $days !== null && $days > 0 ? $days : null,
            'liters' => $hasLiters ? round($liters, 2) : null,
            'liters_per_100_km' => $hasKm && $hasLiters ? round($liters / $km * 100, 1) : null,
            'km_per_liter' => $hasKm && $hasLiters ? round($km / $liters, 2) : null,
            'km_per_day' => $hasKm && $days !== null && $days > 0 ? round($km / $days, 0) : null,
            'tons' => $tons !== null && $tons > 0.0 ? round($tons, 2) : null,
            'invoice_per_km' => $hasKm && $invoice !== null ? round($invoice / $km, 2) : null,
            'cost_per_km' => $hasKm && $operatingCost !== null ? round($operatingCost / $km, 2) : null,
            'fuel_cost_per_km' => $hasKm && $fuelAmount > 0.0 ? round($fuelAmount / $km, 2) : null,
            'margin_per_km' => $hasKm && $margin !== null ? round($margin / $km, 2) : null,
            'invoice_per_ton' => $invoice !== null && $tons !== null && $tons > 0.0 ? round($invoice / $tons, 2) : null,
        ];
    }

    /**
     * Días calendario del viaje, contando el de salida. Si todavía no regresó,
     * se cuenta hasta la fecha de referencia (hoy).
     */
    public static function days(?string $departure, ?string $return, string $today): ?int
    {
        if ($departure === null || trim($departure) === '') {
            return null;
        }
        $start = strtotime(substr($departure, 0, 10));
        $endSource = $return !== null && trim($return) !== '' ? $return : $today;
        $end = strtotime(substr($endSource, 0, 10));
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return (int) round(($end - $start) / 86400) + 1;
    }
}
