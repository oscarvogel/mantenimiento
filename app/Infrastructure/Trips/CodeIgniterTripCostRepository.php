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
            'concepto_id' => $expense->conceptId(),
            'cantidad' => $this->decimal($expense->quantity(), 2),
            'importe_unitario' => $expense->unitAmount() === null ? null : $this->decimal($expense->unitAmount(), 4),
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

    public function updateFuel(int $tripId, int $companyId, int $movementId, TripFuelLoad $load, int $actorUserId, ?int $legId = null): bool
    {
        return $this->updateMovement('viaje_operativo_combustible', 'COMBUSTIBLE', $tripId, $companyId, $movementId, $actorUserId, [
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

    public function updateExpense(int $tripId, int $companyId, int $movementId, TripExpense $expense, int $actorUserId, ?int $legId = null): bool
    {
        return $this->updateMovement('viaje_operativo_gastos', 'GASTO', $tripId, $companyId, $movementId, $actorUserId, [
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

    public function updateCashMovement(int $tripId, int $companyId, int $movementId, TripCashMovement $movement, int $actorUserId): bool
    {
        return $this->updateMovement('viaje_operativo_caja_movimientos', 'CAJA', $tripId, $companyId, $movementId, $actorUserId, [
            'tipo' => $movement->type(),
            'fecha' => $movement->date()->format('Y-m-d H:i:s'),
            ...$this->moneyColumns($movement->money()),
            'observaciones' => $movement->notes(),
        ]);
    }

    public function softDeleteFuel(int $tripId, int $companyId, int $movementId, int $actorUserId): bool
    {
        return $this->softDeleteMovement('viaje_operativo_combustible', 'COMBUSTIBLE', $tripId, $companyId, $movementId, $actorUserId);
    }

    public function softDeleteExpense(int $tripId, int $companyId, int $movementId, int $actorUserId): bool
    {
        return $this->softDeleteMovement('viaje_operativo_gastos', 'GASTO', $tripId, $companyId, $movementId, $actorUserId);
    }

    public function softDeleteCashMovement(int $tripId, int $companyId, int $movementId, int $actorUserId): bool
    {
        return $this->softDeleteMovement('viaje_operativo_caja_movimientos', 'CAJA', $tripId, $companyId, $movementId, $actorUserId);
    }

    public function addOtherEarning(int $tripId, int $companyId, int $branchId, TripOtherEarning $earning, int $actorUserId, ?int $logbookEntryId = null): int
    {
        return $this->insert('viaje_operativo_otros_haberes', $tripId, $companyId, $branchId, $actorUserId, [
            'empleado_id' => $earning->employeeId(),
            'concepto_id' => $earning->conceptId(),
            'diario_bordo_id' => $logbookEntryId,
            'cantidad' => $this->decimal($earning->quantity(), 2),
            'importe_unitario' => $earning->unitAmount() === null ? null : $this->decimal($earning->unitAmount(), 4),
            'fecha' => $earning->date()->format('Y-m-d'),
            'concepto' => $earning->concept(),
            ...$this->moneyColumns($earning->money()),
            'observaciones' => $earning->notes(),
        ]);
    }

    public function updateOtherEarning(int $tripId, int $companyId, int $movementId, TripOtherEarning $earning, int $actorUserId, ?int $logbookEntryId = null): bool
    {
        return $this->updateMovement('viaje_operativo_otros_haberes', 'HABER', $tripId, $companyId, $movementId, $actorUserId, [
            'empleado_id' => $earning->employeeId(),
            'concepto_id' => $earning->conceptId(),
            'diario_bordo_id' => $logbookEntryId,
            'cantidad' => $this->decimal($earning->quantity(), 2),
            'importe_unitario' => $earning->unitAmount() === null ? null : $this->decimal($earning->unitAmount(), 4),
            'fecha' => $earning->date()->format('Y-m-d'),
            'concepto' => $earning->concept(),
            ...$this->moneyColumns($earning->money()),
            'observaciones' => $earning->notes(),
        ]);
    }

    public function logbookEntryIdForEarning(int $tripId, int $companyId, int $movementId): ?int
    {
        $row = $this->database->table('viaje_operativo_otros_haberes')
            ->select('diario_bordo_id')
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->where('id', $movementId)
            ->where('deleted_at', null)
            ->get()->getRowArray();

        $id = $row['diario_bordo_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function softDeleteOtherEarning(int $tripId, int $companyId, int $movementId, int $actorUserId): bool
    {
        return $this->softDeleteMovement('viaje_operativo_otros_haberes', 'HABER', $tripId, $companyId, $movementId, $actorUserId);
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

    /** @param array<string,mixed> $changes */
    private function updateMovement(string $table, string $type, int $tripId, int $companyId, int $movementId, int $actorUserId, array $changes): bool
    {
        $before = $this->activeMovement($table, $tripId, $companyId, $movementId);
        if ($before === null) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $changes += ['updated_at' => $now, 'updated_by' => $actorUserId];
        $updated = $this->database->table($table)
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->where('id', $movementId)
            ->where('deleted_at', null)
            ->update($changes);
        if (! $updated) {
            throw new RuntimeException('No se pudieron guardar los cambios del movimiento.');
        }

        $this->auditMovement($companyId, (int) $before['sucursal_id'], $tripId, $type, $movementId, 'EDICION', $before, array_merge($before, $changes), $actorUserId, $now);

        return true;
    }

    private function softDeleteMovement(string $table, string $type, int $tripId, int $companyId, int $movementId, int $actorUserId): bool
    {
        $before = $this->activeMovement($table, $tripId, $companyId, $movementId);
        if ($before === null) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $changes = ['updated_at' => $now, 'updated_by' => $actorUserId, 'deleted_at' => $now, 'deleted_by' => $actorUserId];
        $updated = $this->database->table($table)
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->where('id', $movementId)
            ->where('deleted_at', null)
            ->update($changes);
        if (! $updated) {
            throw new RuntimeException('No se pudo quitar el movimiento del viaje.');
        }

        $this->auditMovement($companyId, (int) $before['sucursal_id'], $tripId, $type, $movementId, 'BAJA_LOGICA', $before, array_merge($before, $changes), $actorUserId, $now);

        return true;
    }

    /** @return array<string,mixed>|null */
    private function activeMovement(string $table, int $tripId, int $companyId, int $movementId): ?array
    {
        $row = $this->database->table($table)
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->where('id', $movementId)
            ->where('deleted_at', null)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $after */
    private function auditMovement(int $companyId, int $branchId, int $tripId, string $type, int $movementId, string $action, ?array $before, ?array $after, int $actorUserId, string $at): void
    {
        $inserted = $this->database->table('viaje_operativo_movimientos_auditoria')->insert([
            'empresa_id' => $companyId,
            'sucursal_id' => $branchId,
            'viaje_id' => $tripId,
            'movimiento_tipo' => $type,
            'movimiento_id' => $movementId,
            'accion' => $action,
            'datos_anteriores' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'datos_nuevos' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'usuario_id' => $actorUserId,
            'created_at' => $at,
        ]);
        if (! $inserted) {
            throw new RuntimeException('No se pudo registrar la auditoría del movimiento.');
        }
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
