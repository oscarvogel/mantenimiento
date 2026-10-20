<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripReadModel;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripCashMovement;
use App\Domain\Trips\TripConsumption;
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
        $detail['links'] = [
            'detail' => base_url('viajes/operativos/' . $tripId),
            'fuel' => base_url('viajes/operativos/' . $tripId . '/combustible'),
            'expense' => base_url('viajes/operativos/' . $tripId . '/gastos'),
            'cash' => base_url('viajes/operativos/' . $tripId . '/caja'),
        ];
        $detail['canOperate'] = $actor->hasPermission('viajes.operar');
        $detail['canRegisterCosts'] = $detail['canOperate']
            && in_array($detail['trip']['estado'] ?? null, [Trip::PLANIFICADO, Trip::EN_CURSO], true);
        $detail['canStart'] = $detail['canOperate'] && ($detail['trip']['estado'] ?? null) === Trip::PLANIFICADO;
        $detail['canCancel'] = $detail['canOperate'] && ($detail['trip']['estado'] ?? null) === Trip::PLANIFICADO;

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
        foreach ($expenses as $row) {
            $expenseAmount += (float) $row['importe_ars'];
            if (($row['pagado_de_caja'] ?? false) === true) {
                $cashExpenses += (float) $row['importe_ars'];
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
