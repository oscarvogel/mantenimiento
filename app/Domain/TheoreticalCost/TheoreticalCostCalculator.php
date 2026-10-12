<?php

declare(strict_types=1);

namespace App\Domain\TheoreticalCost;

use DomainException;

/** Pure calculator for a theoretical tractor/semi operating-cost scenario. */
final class TheoreticalCostCalculator
{
    /** @param array<string,mixed> $parameters @param array<string,mixed>|null $trip */
    public function calculate(array $parameters, ?array $trip = null): array
    {
        $missing = [];
        $items = [];
        $monthlyKm = $this->positive($parameters['km_mensuales'] ?? null);
        $fx = is_array($parameters['fx'] ?? null) ? $parameters['fx'] : [];
        $exchangeRate = $this->positive($fx['ars_por_usd'] ?? null);
        $fxDate = $this->date($fx['fecha'] ?? null);
        $fxSource = $this->text($fx['fuente'] ?? null);
        $hasFx = $exchangeRate !== null && $fxDate !== null && $fxSource !== null;
        $conversionRate = $hasFx ? $exchangeRate : null;

        if ($monthlyKm === null) {
            $missing[] = 'km_mensuales';
        }
        if (! $hasFx) {
            $missing[] = 'cotizacion_usd';
        }

        $tractor = is_array($parameters['tractor'] ?? null) ? $parameters['tractor'] : [];
        $semi = is_array($parameters['semi'] ?? null) ? $parameters['semi'] : [];
        foreach (['tractor' => $tractor, 'semi' => $semi] as $asset => $values) {
            $value = $this->nonNegative($values['valor_usd'] ?? null);
            $residual = $this->nonNegative($values['residual_usd'] ?? null);
            $lifeYears = $this->positive($values['vida_util_anios'] ?? null);
            if ($value === null || $residual === null || $lifeYears === null) {
                $missing[] = $asset;
                $items[] = $this->line($asset, $asset === 'tractor' ? 'Depreciación tractor' : 'Depreciación semi', null, 'USD/mes', null, $monthlyKm);
                continue;
            }
            if ($residual > $value) {
                throw new DomainException('El valor residual no puede superar el valor actual del activo.');
            }
            $monthlyUsd = ($value - $residual) / ($lifeYears * 12);
            $this->appendMonthly($items, $asset, $asset === 'tractor' ? 'Depreciación tractor' : 'Depreciación semi', $monthlyUsd, 'USD', $conversionRate, $monthlyKm);
        }

        $fuel = is_array($parameters['combustible'] ?? null) ? $parameters['combustible'] : [];
        $fuelUse = $this->positive($fuel['litros_100km'] ?? null);
        $fuelPrice = $this->nonNegative($fuel['precio'] ?? null);
        $fuelCurrency = $this->currency($fuel['moneda'] ?? null);
        if ($fuelUse === null || $fuelPrice === null || $fuelCurrency === null || $this->date($fuel['fecha'] ?? null) === null) {
            $missing[] = 'combustible';
            $items[] = $this->line('combustible', 'Combustible', null, 'por_km', null, $monthlyKm);
        } else {
            $this->appendPerKm($items, 'combustible', 'Combustible', $fuelUse * $fuelPrice / 100, $fuelCurrency, $conversionRate, $monthlyKm);
        }

        $tires = is_array($parameters['neumaticos'] ?? null) ? $parameters['neumaticos'] : [];
        $tireSet = $this->nonNegative($tires['costo_juego'] ?? null);
        $retreads = $this->nonNegative($tires['recapados'] ?? null);
        $tireLife = $this->positive($tires['vida_util_km'] ?? null);
        $tireCurrency = $this->currency($tires['moneda'] ?? null);
        if ($tireSet === null || $retreads === null || $tireLife === null || $tireCurrency === null) {
            $missing[] = 'neumaticos';
            $items[] = $this->line('neumaticos', 'Neumáticos y recapados', null, 'por_km', null, $monthlyKm);
        } else {
            $this->appendPerKm($items, 'neumaticos', 'Neumáticos y recapados', ($tireSet + $retreads) / $tireLife, $tireCurrency, $conversionRate, $monthlyKm);
        }

        $monthlyCosts = is_array($parameters['costos_mensuales'] ?? null) ? $parameters['costos_mensuales'] : [];
        foreach ($monthlyCosts as $index => $cost) {
            if (! is_array($cost)) {
                $missing[] = 'costos_mensuales.' . $index;
                continue;
            }
            $code = $this->code($cost['codigo'] ?? null, 'costo_mensual_' . $index);
            $name = $this->text($cost['nombre'] ?? null) ?? 'Costo mensual';
            $amount = $this->nonNegative($cost['importe'] ?? null);
            $currency = $this->currency($cost['moneda'] ?? null);
            if ($amount === null || $currency === null) {
                $missing[] = $code;
                $items[] = $this->line($code, $name, null, 'por_mes', null, $monthlyKm);
                continue;
            }
            $this->appendMonthly($items, $code, $name, $amount, $currency, $conversionRate, $monthlyKm);
        }

        $otherCosts = is_array($parameters['otros_rubros'] ?? null) ? $parameters['otros_rubros'] : [];
        $financialPercent = null;
        $lubricantPercent = null;
        foreach ($otherCosts as $index => $cost) {
            if (! is_array($cost)) {
                $missing[] = 'otros_rubros.' . $index;
                continue;
            }
            $code = $this->code($cost['codigo'] ?? null, 'otro_rubro_' . $index);
            $name = $this->text($cost['nombre'] ?? null) ?? 'Otro rubro';
            $amount = $this->nonNegative($cost['importe'] ?? null);
            $currency = $this->currency($cost['moneda'] ?? null);
            $unit = strtolower(trim((string) ($cost['unidad'] ?? '')));
            if (in_array($code, ['lubricantes', 'costo_financiero'], true) && $unit === 'porcentaje') {
                if ($amount === null) {
                    $missing[] = $code;
                    if ($code === 'lubricantes') {
                        $items[] = $this->line($code, $name, null, 'porcentaje_combustible', null, $monthlyKm);
                    }
                    continue;
                }
                if ($code === 'lubricantes') {
                    $lubricantPercent = $amount;
                    $fuelLine = $this->findItem($items, 'combustible');
                    $fuelArsKm = $fuelLine['ars_km'] ?? null;
                    if ($fuelArsKm === null && $amount > 0) {
                        $missing[] = 'lubricantes';
                        $items[] = $this->line($code, $name, null, 'porcentaje_combustible', null, $monthlyKm);
                    } else {
                        $lubricantArsKm = ($fuelArsKm ?? 0) * $amount / 100;
                        $items[] = $this->percentageLine($code, $name, $amount, $lubricantArsKm, $conversionRate, 'porcentaje_combustible');
                    }
                } else {
                    $financialPercent = $amount;
                }
                continue;
            }
            if ($amount === null || $currency === null || ! in_array($unit, ['por_mes', 'por_km'], true)) {
                $missing[] = $code;
                $items[] = $this->line($code, $name, null, $unit ?: 'sin_unidad', null, $monthlyKm);
                continue;
            }
            if ($unit === 'por_mes') {
                $this->appendMonthly($items, $code, $name, $amount, $currency, $conversionRate, $monthlyKm);
            } else {
                $this->appendPerKm($items, $code, $name, $amount, $currency, $conversionRate, $monthlyKm);
            }
        }
        if ($lubricantPercent === null) {
            $missing[] = 'lubricantes';
            if ($this->findItem($items, 'lubricantes') === null) {
                $items[] = $this->line('lubricantes', 'Lubricantes', null, 'porcentaje_combustible', null, $monthlyKm);
            }
        }

        $knownBaseArs = 0.0;
        $hasUnconvertedUsd = false;
        foreach ($items as $item) {
            if ($item['estado'] !== 'configurado') {
                continue;
            }
            if ($item['ars_km'] === null) {
                $hasUnconvertedUsd = true;
                $missing[] = 'cotizacion_usd';
                $missing[] = $item['codigo'];
                continue;
            }
            $knownBaseArs += (float) $item['ars_km'];
        }

        $maintenancePercent = $this->nonNegative($parameters['mantenimiento_pct'] ?? null);
        if ($maintenancePercent !== null && $this->hasSeparateMaintenance($monthlyCosts, $otherCosts)) {
            throw new DomainException('Si configurás mantenimiento como porcentaje, no agregues otro rubro de mantenimiento o reparaciones en el mismo escenario.');
        }
        if ($maintenancePercent === null) {
            $missing[] = 'mantenimiento_pct';
        } elseif ($maintenancePercent >= 100) {
            throw new DomainException('El porcentaje de mantenimiento debe ser menor al 100 %.');
        }

        if ($financialPercent === null) {
            $missing[] = 'costo_financiero';
        } elseif ($financialPercent >= 100) {
            throw new DomainException('El porcentaje de costo financiero debe ser menor al 100 %.');
        }
        if ($maintenancePercent !== null && $financialPercent !== null && $maintenancePercent + $financialPercent >= 100) {
            throw new DomainException('La suma de mantenimiento y costo financiero debe ser menor al 100 %.');
        }

        $surchargePercent = $this->nonNegative($parameters['recargo_pct'] ?? null);
        if ($surchargePercent === null) {
            $missing[] = 'recargo_pct';
        }

        $complete = $missing === [] && ! $hasUnconvertedUsd && $monthlyKm !== null;
        $totalArs = $knownBaseArs;
        $combinedPercent = ($maintenancePercent ?? 0) + ($financialPercent ?? 0);
        if ($combinedPercent < 100) {
            $totalArs = $knownBaseArs / (1 - ($combinedPercent / 100));
        }
        if ($maintenancePercent !== null) {
            $maintArs = $totalArs * $maintenancePercent / 100;
            $items[] = [
                'codigo' => 'mantenimiento',
                'nombre' => 'Mantenimiento y reparaciones',
                'estado' => 'configurado',
                'unidad' => 'por_km',
                'importe_origen' => $maintenancePercent,
                'moneda_origen' => '%',
                'ars_km' => $maintArs,
                'usd_km' => $hasFx ? $maintArs / $exchangeRate : null,
            ];
        } else {
            $items[] = $this->line('mantenimiento', 'Mantenimiento y reparaciones', null, 'por_km', null, $monthlyKm);
        }
        if ($financialPercent !== null) {
            $items[] = $this->percentageLine('costo_financiero', 'Costo financiero', $financialPercent, $totalArs * $financialPercent / 100, $conversionRate, 'porcentaje_costo_total');
        } else {
            $items[] = $this->line('costo_financiero', 'Costo financiero', null, 'porcentaje_costo_total', null, $monthlyKm);
        }
        $totalUsd = $hasFx ? $totalArs / $exchangeRate : null;
        $chargeArs = $surchargePercent === null ? null : $totalArs * (1 + ($surchargePercent / 100));
        $chargeUsd = $chargeArs === null || ! $hasFx ? null : $chargeArs / $exchangeRate;

        return [
            'cobertura' => $complete ? 'completa' : 'parcial',
            'faltantes' => array_values(array_unique($missing)),
            'moneda_base' => 'ARS',
            'cotizacion' => [
                'ars_por_usd' => $exchangeRate,
                'fecha' => $fxDate,
                'fuente' => $fxSource,
                'valida' => $hasFx,
            ],
            'km_mensuales' => $monthlyKm,
            'recargo_pct' => $surchargePercent,
            'mantenimiento_pct' => $maintenancePercent,
            'costo_financiero_pct' => $financialPercent,
            'costo_km' => ['ars' => $totalArs, 'usd' => $totalUsd],
            'tarifa_km' => ['ars' => $chargeArs, 'usd' => $chargeUsd],
            'rubros' => $items,
            'viaje' => $trip === null ? null : $this->calculateTrip($trip, $totalArs, $exchangeRate, $hasFx, $surchargePercent, $complete),
        ];
    }

    /** @return array<string,mixed> */
    private function calculateTrip(array $trip, float $costKmArs, ?float $fx, bool $hasFx, ?float $surcharge, bool $baseComplete): array
    {
        $billable = $this->nonNegative($trip['km_facturables'] ?? null);
        $nonBillable = $this->nonNegative($trip['km_no_facturados'] ?? null);
        $tolls = $this->nonNegative($trip['peajes'] ?? null);
        $currency = $this->currency($trip['moneda'] ?? null);
        $valid = $billable !== null && $billable > 0 && $nonBillable !== null && $tolls !== null && $currency !== null;
        $tollsArs = null;
        if ($tolls !== null && $currency === 'ARS') {
            $tollsArs = $tolls;
        } elseif ($tolls !== null && $currency === 'USD' && $hasFx && $fx !== null) {
            $tollsArs = $tolls * $fx;
        }
        if ($tollsArs === null) {
            $valid = false;
        }
        $tripCost = $valid ? $costKmArs * ($billable + $nonBillable) + $tollsArs : null;
        $charge = $valid && $surcharge !== null && $tripCost !== null ? $tripCost * (1 + $surcharge / 100) / $billable : null;

        return [
            'km_facturables' => $billable,
            'km_no_facturados' => $nonBillable,
            'km_totales' => $billable !== null && $nonBillable !== null ? $billable + $nonBillable : null,
            'peajes' => $tolls,
            'peajes_moneda' => $currency,
            'peajes_ars' => $tollsArs,
            'costo_ars' => $tripCost,
            'tarifa_ars_km' => $charge,
            'tarifa_usd_km' => $charge !== null && $hasFx && $fx !== null ? $charge / $fx : null,
            'estado' => $valid && $charge !== null && $baseComplete ? 'calculado' : 'incompleto',
            'cobertura' => $baseComplete ? 'completa' : 'parcial',
        ];
    }

    private function hasSeparateMaintenance(array $monthly, array $other): bool
    {
        foreach (array_merge($monthly, $other) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = (string) ($item['codigo'] ?? '') . ' ' . (string) ($item['nombre'] ?? '');
            if (preg_match('/mantenim|reparaci/iu', $label) === 1 && ($this->nonNegative($item['importe'] ?? null) ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,array<string,mixed>> $items @return array<string,mixed>|null */
    private function findItem(array $items, string $code): ?array
    {
        foreach ($items as $item) {
            if (($item['codigo'] ?? null) === $code) {
                return $item;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function percentageLine(string $code, string $name, float $percent, float $arsKm, ?float $fx, string $unit): array
    {
        return [
            'codigo' => $code,
            'nombre' => $name,
            'estado' => 'configurado',
            'unidad' => $unit,
            'importe_origen' => $percent,
            'moneda_origen' => '%',
            'ars_km' => $arsKm,
            'usd_km' => $fx !== null ? $arsKm / $fx : null,
        ];
    }

    private function appendMonthly(array &$items, string $code, string $name, float $amount, string $currency, ?float $fx, ?float $km): void
    {
        if ($km === null) {
            $items[] = [
                'codigo' => $code,
                'nombre' => $name,
                'estado' => 'pendiente_km_mensuales',
                'unidad' => 'por_mes',
                'importe_origen' => $amount,
                'moneda_origen' => $currency,
                'ars_km' => null,
                'usd_km' => null,
            ];
            return;
        }
        $perKm = $amount / $km;
        $this->appendPerKm($items, $code, $name, $perKm, $currency, $fx, $km, 'por_mes');
    }

    private function appendPerKm(array &$items, string $code, string $name, float $amount, string $currency, ?float $fx, ?float $km, string $unit = 'por_km'): void
    {
        $ars = $currency === 'ARS' ? $amount : ($fx !== null ? $amount * $fx : null);
        $items[] = [
            'codigo' => $code,
            'nombre' => $name,
            'estado' => $ars === null ? 'sin_cotizacion' : 'configurado',
            'unidad' => $unit,
            'importe_origen' => $amount,
            'moneda_origen' => $currency,
            'ars_km' => $ars,
            'usd_km' => $ars !== null && $fx !== null ? $ars / $fx : null,
        ];
    }

    private function line(string $code, string $name, ?float $amount, string $unit, ?float $arsKm, ?float $km): array
    {
        return ['codigo' => $code, 'nombre' => $name, 'estado' => 'faltante', 'unidad' => $unit, 'importe_origen' => $amount, 'moneda_origen' => null, 'ars_km' => $arsKm, 'usd_km' => null];
    }

    private function positive(mixed $value): ?float
    {
        $number = $this->number($value);
        return $number !== null && $number > 0 ? $number : null;
    }

    private function nonNegative(mixed $value): ?float
    {
        $number = $this->number($value);
        return $number !== null && $number >= 0 ? $number : null;
    }

    private function number(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }

    private function currency(mixed $value): ?string
    {
        $currency = strtoupper(trim((string) $value));
        return in_array($currency, ['ARS', 'USD'], true) ? $currency : null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, 150);
    }

    private function code(mixed $value, string $fallback): string
    {
        $code = is_string($value) ? preg_replace('/[^a-zA-Z0-9_.-]/', '', trim($value)) : '';
        return $code === '' ? $fallback : mb_substr($code, 0, 80);
    }
}
