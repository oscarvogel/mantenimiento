<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\TripCostRepository;
use App\Domain\Trips\TripCashMovement;
use App\Domain\Trips\TripExpense;
use App\Domain\Trips\TripFuelLoad;
use App\Domain\Trips\TripMoney;
use App\Domain\Trips\TripOtherEarning;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

final class CodeIgniterTripCostRepository implements TripCostRepository
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function addFuel(int $tripId, int $companyId, int $branchId, TripFuelLoad $load, int $actorUserId, ?int $legId = null): int
    {
        return $this->insert('viaje_operativo_combustible', $tripId, $companyId, $branchId, $actorUserId, [
            'tramo_id' => $legId,
            'fecha' => $load->date()->format('Y-m-d H:i:s'),
            'litros' => $this->decimal($load->liters(), 2),
            ...$this->moneyColumns($load->money()),
            'kilometraje' => $load->odometer(),
            'estacion' => $load->station(),
            'comprobante' => $load->receipt(),
            'observaciones' => $load->notes(),
        ]);
    }

    public function addExpense(int $tripId, int $companyId, int $branchId, TripExpense $expense, int $actorUserId, ?int $legId = null): int
    {
        return $this->insert('viaje_operativo_gastos', $tripId, $companyId, $branchId, $actorUserId, [
            'tramo_id' => $legId,
            'categoria_id' => $expense->categoryId(),
            'fecha' => $expense->date()->format('Y-m-d H:i:s'),
            ...$this->moneyColumns($expense->money()),
            'medio_pago_id' => $expense->paymentMethodId(),
            'pagado_de_caja' => $expense->paidFromCash() ? 1 : 0,
            'pagado_por' => $expense->paidBy(),
            'comprobante' => $expense->receipt(),
            'observaciones' => $expense->notes(),
        ]);
    }

    public function addCashMovement(int $tripId, int $companyId, int $branchId, TripCashMovement $movement, int $actorUserId): int
    {
        return $this->insert('viaje_operativo_caja_movimientos', $tripId, $companyId, $branchId, $actorUserId, [
            'tipo' => $movement->type(),
            'fecha' => $movement->date()->format('Y-m-d H:i:s'),
            ...$this->moneyColumns($movement->money()),
            'observaciones' => $movement->notes(),
        ]);
    }

    public function addOtherEarning(int $tripId, int $companyId, int $branchId, TripOtherEarning $earning, int $actorUserId): int
    {
        return $this->insert('viaje_operativo_otros_haberes', $tripId, $companyId, $branchId, $actorUserId, [
            'empleado_id' => $earning->employeeId(),
            'fecha' => $earning->date()->format('Y-m-d'),
            'concepto' => $earning->concept(),
            ...$this->moneyColumns($earning->money()),
            'observaciones' => $earning->notes(),
        ]);
    }

    /** @param array<string,mixed> $data */
    private function insert(string $table, int $tripId, int $companyId, int $branchId, int $actorUserId, array $data): int
    {
        $now = date('Y-m-d H:i:s');
        if (! $this->database->table($table)->insert($data + [
            'viaje_id' => $tripId,
            'empresa_id' => $companyId,
            'sucursal_id' => $branchId,
            'created_at' => $now,
            'created_by' => $actorUserId,
        ])) {
            throw new RuntimeException('No se pudo guardar el movimiento del viaje.');
        }

        $id = (int) $this->database->insertID();
        if ($id <= 0) {
            throw new RuntimeException('No se pudo guardar el movimiento del viaje.');
        }

        return $id;
    }

    /** @return array{importe:string,moneda:string,cotizacion:string,importe_ars:string} */
    private function moneyColumns(TripMoney $money): array
    {
        return [
            'importe' => $this->decimal($money->amount(), 4),
            'moneda' => $money->currency(),
            'cotizacion' => $this->decimal($money->exchangeRate(), 6),
            'importe_ars' => $this->decimal($money->amountArs(), 4),
        ];
    }

    private function decimal(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }
}
