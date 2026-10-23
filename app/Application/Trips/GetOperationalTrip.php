<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripReadModel;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripCashMovement;
use App\Domain\Trips\TripConsumption;
use App\Domain\Trips\TripSettlement;
use DomainException;

final class GetOperationalTrip
{
    public function __construct(private readonly OperationalTripReadModel $readModel)
    {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, int $tripId): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('viajes.ver')) {
            throw new DomainException('No tenés permiso para consultar viajes.');
        }
        if ($tripId <= 0) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }

        $detail = $this->readModel->find($actor->companyId(), $branchIds, $tripId);
        if ($detail === null) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }

        $detail = $this->costs($detail);
        $detail = $this->financials($detail);
        $detail['links'] = [
            'detail' => base_url('viajes/operativos/' . $tripId),
            'fuel' => base_url('viajes/operativos/' . $tripId . '/combustible'),
            'expense' => base_url('viajes/operativos/' . $tripId . '/gastos'),
            'cash' => base_url('viajes/operativos/' . $tripId . '/caja'),
            'earnings' => base_url('viajes/operativos/' . $tripId . '/haberes'),
            'legs' => base_url('viajes/operativos/' . $tripId . '/tramos'),
            'finish' => base_url('viajes/operativos/' . $tripId . '/cerrar'),
        ];
        $detail['canOperate'] = $actor->hasPermission('viajes.operar');
        $detail['canRegisterCosts'] = $detail['canOperate']
            && in_array($detail['trip']['estado'] ?? null, [Trip::PLANIFICADO, Trip::EN_CURSO], true);
        $detail['canStart'] = $detail['canOperate'] && ($detail['trip']['estado'] ?? null) === Trip::PLANIFICADO;
        $detail['canCancel'] = $detail['canOperate'] && ($detail['trip']['estado'] ?? null) === Trip::PLANIFICADO;
        $detail['canClose'] = $detail['canOperate'] && ($detail['trip']['estado'] ?? null) === Trip::EN_CURSO;
        $detail['canAddLegs'] = $detail['canOperate']
            && in_array($detail['trip']['estado'] ?? null, [Trip::PLANIFICADO, Trip::EN_CURSO], true);

        return $detail;
    }

    /** @param array<string,mixed> $detail @return array<string,mixed> */
    private function financials(array $detail): array
    {
        $legs = $detail['legs'] ?? [];
        $assignments = $detail['assignments'] ?? [];
        $fuel = $detail['fuel'] ?? [];
        $expenses = $detail['expenses'] ?? [];

        $drivers = array_values(array_filter(
            $assignments,
            static fn (array $assignment): bool => ($assignment['rol'] ?? '') === 'CHOFER',
        ));
        $driversCount = count($drivers);
        $percentage = $driversCount === 1 && ($drivers[0]['porcentaje_liquidacion'] ?? null) !== null
            ? (float) $drivers[0]['porcentaje_liquidacion']
            : null;
        $driverId = $driversCount === 1 ? ($drivers[0]['empleado_id'] ?? null) : null;
        $otherEarnings = $detail['otherEarnings'] ?? [];
        $otherEarningsAmount = 0.0;
        foreach ($otherEarnings as $row) {
            if ($driverId !== null && (int) ($row['empleado_id'] ?? 0) !== (int) $driverId) {
                continue;
            }
            $otherEarningsAmount += (float) ($row['importe_ars'] ?? 0);
        }

        $legIds = array_fill_keys(array_map(static fn (array $leg): int => (int) $leg['id'], $legs), true);
        $fuelByLeg = [];
        $expenseByLeg = [];
        $totalFuel = 0.0;
        $totalExpenses = 0.0;
        foreach ($fuel as $row) {
            $amount = (float) ($row['importe_ars'] ?? 0);
            $totalFuel += $amount;
            $legId = $row['tramo_id'] ?? null;
            if ($legId !== null && isset($legIds[(int) $legId])) {
                $fuelByLeg[(int) $legId] = ($fuelByLeg[(int) $legId] ?? 0.0) + $amount;
            }
        }
        foreach ($expenses as $row) {
            $amount = (float) ($row['importe_ars'] ?? 0);
            $totalExpenses += $amount;
            $legId = $row['tramo_id'] ?? null;
            if ($legId !== null && isset($legIds[(int) $legId])) {
                $expenseByLeg[(int) $legId] = ($expenseByLeg[(int) $legId] ?? 0.0) + $amount;
            }
        }

        $enrichedLegs = [];
        $totalInvoice = 0.0;
        $totalLiquidation = 0.0;
        $totalAssigned = 0.0;
        $commissions = [];
        foreach ($legs as $leg) {
            $legId = (int) $leg['id'];
            $invoice = (float) ($leg['flete_facturacion_ars'] ?? 0);
            $liquidation = (float) ($leg['flete_liquidacion_ars'] ?? 0);
            $assignedFuel = round($fuelByLeg[$legId] ?? 0.0, 2);
            $assignedExpenses = round($expenseByLeg[$legId] ?? 0.0, 2);
            $assignedCosts = round($assignedFuel + $assignedExpenses, 2);
            $commission = $percentage === null ? null : round($liquidation * $percentage / 100, 2);
            $margin = $commission === null ? null : round($invoice - $assignedCosts - $commission, 2);
            $marginPct = $margin === null || $invoice <= 0 ? null : round($margin / $invoice * 100, 1);

            $totalInvoice += $invoice;
            $totalLiquidation += $liquidation;
            $totalAssigned += $assignedCosts;
            if ($commission !== null) {
                $commissions[] = $commission;
            }

            $enrichedLegs[] = $leg + [
                'commission_ars' => $commission,
                'assigned_fuel_ars' => $assignedFuel,
                'assigned_expenses_ars' => $assignedExpenses,
                'assigned_costs_ars' => $assignedCosts,
                'margin_ars' => $margin,
                'margin_pct' => $marginPct,
            ];
        }

        $totalCommission = $percentage === null ? null : round(array_sum($commissions), 2);
        $unassigned = round($totalFuel + $totalExpenses - $totalAssigned, 2);
        $operatingCost = $totalCommission === null ? null : round($totalFuel + $totalExpenses + $totalCommission + $otherEarningsAmount, 2);
        $margin = $operatingCost === null || $legs === [] ? null : round($totalInvoice - $operatingCost, 2);
        $marginPct = $margin === null || $totalInvoice <= 0 ? null : round($margin / $totalInvoice * 100, 1);

        $settlement = null;
        if ($totalCommission !== null) {
            $settlement = new TripSettlement(
                $totalCommission,
                $otherEarningsAmount,
                (float) ($detail['totals']['driver_expenses_ars'] ?? 0),
                (float) ($detail['totals']['cash_expenses_ars'] ?? 0),
                (float) ($detail['totals']['cash_delivered_ars'] ?? 0),
                (float) ($detail['totals']['cash_returned_ars'] ?? 0),
            );
        }

        $detail['settlement'] = $settlement === null ? null : [
            'driver_id' => $driverId === null ? null : (int) $driverId,
            'driver' => $drivers[0]['label'] ?? null,
            'commission_ars' => $settlement->commission(),
            'other_earnings_ars' => $settlement->otherEarnings(),
            'driver_expenses_ars' => $settlement->driverExpenses(),
            'cash_expenses_ars' => $settlement->cashExpenses(),
            'advances_ars' => $settlement->advances(),
            'returns_ars' => $settlement->returns(),
            'account_balance_ars' => $settlement->accountBalance(),
            'total_ars' => $settlement->total(),
        ];

        $detail['legs'] = $enrichedLegs;
        $detail['totals'] = ($detail['totals'] ?? []) + [
            'has_legs' => $legs !== [],
            'invoice_ars' => round($totalInvoice, 2),
            'liquidation_ars' => round($totalLiquidation, 2),
            'commission_ars' => $totalCommission,
            'assigned_costs_ars' => round($totalAssigned, 2),
            'unassigned_costs_ars' => $unassigned,
            'operating_cost_ars' => $operatingCost,
            'margin_ars' => $margin,
            'margin_pct' => $marginPct,
            'driver_percentage' => $percentage,
            'drivers_count' => $driversCount,
            'multiple_drivers' => $driversCount > 1,
            'other_earnings_ars' => round($otherEarningsAmount, 2),
            'settlement_available' => $settlement !== null,
            'settlement_total_ars' => $settlement?->total(),
        ];

        return $detail;
    }

    /** @param array<string,mixed> $detail @return array<string,mixed> */
    private function costs(array $detail): array
    {
        $fuel = $detail['fuel'] ?? [];
        $expenses = $detail['expenses'] ?? [];
        $cashMovements = $detail['cashMovements'] ?? [];

        $fuelLiters = 0.0;
        $fuelAmount = 0.0;
        $odometers = [];
        foreach ($fuel as $row) {
            $fuelLiters += (float) $row['litros'];
            $fuelAmount += (float) $row['importe_ars'];
            if ($row['kilometraje'] !== null) {
                $odometers[] = (int) $row['kilometraje'];
            }
        }
        $expenseAmount = 0.0;
        $cashExpenses = 0.0;
        $driverExpenses = 0.0;
        foreach ($expenses as $row) {
            $expenseAmount += (float) $row['importe_ars'];
            $paidBy = (string) ($row['pagado_por'] ?? (($row['pagado_de_caja'] ?? false) ? 'CAJA' : 'EMPRESA'));
            if ($paidBy === 'CAJA') {
                $cashExpenses += (float) $row['importe_ars'];
            } elseif ($paidBy === 'CHOFER') {
                $driverExpenses += (float) $row['importe_ars'];
            }
        }
        $cashDelivered = 0.0;
        $cashReturned = 0.0;
        foreach ($cashMovements as $row) {
            if ((string) ($row['tipo'] ?? '') === TripCashMovement::DEVOLUCION) {
                $cashReturned += (float) $row['importe_ars'];
            } else {
                $cashDelivered += (float) $row['importe_ars'];
            }
        }

        $km = null;
        $source = null;
        $start = $detail['trip']['km_salida'] ?? null;
        $end = $detail['trip']['km_regreso'] ?? null;
        if ($start !== null && $end !== null && (int) $end > (int) $start) {
            $km = (int) $end - (int) $start;
            $source = 'ODOMETRO_VIAJE';
        } elseif (count($odometers) >= 2 && max($odometers) > min($odometers)) {
            $km = max($odometers) - min($odometers);
            $source = 'ODOMETRO_COMBUSTIBLE';
        }

        $detail['totals'] = [
            'fuel_liters' => round($fuelLiters, 2),
            'fuel_amount_ars' => round($fuelAmount, 2),
            'expense_amount_ars' => round($expenseAmount, 2),
            'direct_cost_ars' => round($fuelAmount + $expenseAmount, 2),
            'cash_delivered_ars' => round($cashDelivered, 2),
            'cash_returned_ars' => round($cashReturned, 2),
            'cash_expenses_ars' => round($cashExpenses, 2),
            'driver_expenses_ars' => round($driverExpenses, 2),
            'cash_balance_ars' => round($cashDelivered - $cashReturned - $cashExpenses, 2),
        ];
        $detail['consumption'] = [
            'km' => $km,
            'liters_per_100' => $km === null ? null : TripConsumption::litersPer100Km($fuelLiters, $km),
            'source' => $source,
        ];

        return $detail;
    }
}
