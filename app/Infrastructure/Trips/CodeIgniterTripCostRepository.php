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
            ...$this->fuelMoneyColumns($load),
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

    public function updateFuel(int $tripId, int $companyId, int $movementId, TripFuelLoad $load, int $actorUserId, ?int $legId = null, ?string $changeReason = null): bool
    {
        return $this->updateMovement('viaje_operativo_combustible', 'COMBUSTIBLE', $tripId, $companyId, $movementId, $actorUserId, [
            'tramo_id' => $legId,
            'fecha' => $load->date()->format('Y-m-d H:i:s'),
            'litros' => $this->decimal($load->liters(), 2),
            ...$this->fuelMoneyColumns($load),
            'kilometraje' => $load->odometer(),
            'estacion' => $load->station(),
            'comprobante' => $load->receipt(),
            'observaciones' => $load->notes(),
        ], $changeReason);
    }

    public function updateExpense(int $tripId, int $companyId, int $movementId, TripExpense $expense, int $actorUserId, ?int $legId = null, ?string $changeReason = null): bool
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
        ], $changeReason);
    }

    public function updateCashMovement(int $tripId, int $companyId, int $movementId, TripCashMovement $movement, int $actorUserId, ?string $changeReason = null): bool
    {
        return $this->updateMovement('viaje_operativo_caja_movimientos', 'CAJA', $tripId, $companyId, $movementId, $actorUserId, [
            'tipo' => $movement->type(),
            'fecha' => $movement->date()->format('Y-m-d H:i:s'),
            ...$this->moneyColumns($movement->money()),
            'observaciones' => $movement->notes(),
        ], $changeReason);
    }

    public function softDeleteFuel(int $tripId, int $companyId, int $movementId, int $actorUserId): bool
    {
        return $this->softDeleteMovement('viaje_operativo_combustible', 'COMBUSTIBLE', $tripId, $companyId, $movementId, $actorUserId);
    }

    public function softDeleteExpense(int $tripId, int $companyId, int $movementId, int $actorUserId): bool
    {
        return $this->softDeleteMovement('viaje_operativo_gastos', 'GASTO', $tripId, $companyId, $movementId, $actorUserId);
    }

    public function reattributeCompanyExpensesToCash(int $tripId, int $companyId, int $actorUserId): int
    {
        $rows = $this->database->table('viaje_operativo_gastos')
            ->select('id')
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->where('deleted_at', null)
            ->groupStart()
                ->where('pagado_por', 'EMPRESA')
                ->orWhere('pagado_por', null)
                ->orWhere('pagado_por', '')
            ->groupEnd()
            ->get()->getResultArray();

        $reattributed = 0;
        foreach ($rows as $row) {
            $movementId = (int) $row['id'];
            if ($this->updateMovement('viaje_operativo_gastos', 'GASTO', $tripId, $companyId, $movementId, $actorUserId, [
                'pagado_de_caja' => 1,
                'pagado_por' => 'CAJA',
            ])) {
                $reattributed++;
            }
        }

        return $reattributed;
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

    public function updateOtherEarning(int $tripId, int $companyId, int $movementId, TripOtherEarning $earning, int $actorUserId, ?int $logbookEntryId = null, ?string $changeReason = null): bool
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
        ], $changeReason);
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

    public function findCashMovement(int $tripId, int $companyId, int $movementId): ?array
    {
        return $this->activeMovement('viaje_operativo_caja_movimientos', $tripId, $companyId, $movementId);
    }

    public function findFuelLoad(int $tripId, int $companyId, int $movementId): ?array
    {
        return $this->activeMovement('viaje_operativo_combustible', $tripId, $companyId, $movementId);
    }

    public function findExpense(int $tripId, int $companyId, int $movementId): ?array
    {
        return $this->activeMovement('viaje_operativo_gastos', $tripId, $companyId, $movementId);
    }

    public function findOtherEarning(int $tripId, int $companyId, int $movementId): ?array
    {
        return $this->activeMovement('viaje_operativo_otros_haberes', $tripId, $companyId, $movementId);
    }

    public function suspiciousHistoricalMovements(int $companyId): array
    {
        $rows = [];
        $defs = [
            ['table' => 'viaje_operativo_caja_movimientos', 'tipo' => 'CAJA', 'fecha' => 'fecha', 'amount' => 'importe'],
            ['table' => 'viaje_operativo_gastos', 'tipo' => 'GASTO', 'fecha' => 'fecha', 'amount' => 'importe'],
            ['table' => 'viaje_operativo_combustible', 'tipo' => 'COMBUSTIBLE', 'fecha' => 'fecha', 'amount' => 'importe'],
            ['table' => 'viaje_operativo_otros_haberes', 'tipo' => 'HABER', 'fecha' => 'fecha', 'amount' => 'importe'],
        ];

        foreach ($defs as $def) {
            $builder = $this->database->table($def['table'] . ' m')
                ->select('m.id, m.viaje_id, m.fecha, m.importe, m.moneda, m.cotizacion, m.importe_ars, m.created_at, v.estado AS viaje_estado, v.sucursal_id, v.numero AS viaje_numero')
                ->join('viajes_operativos v', 'v.id = m.viaje_id AND v.empresa_id = m.empresa_id')
                ->where('m.empresa_id', $companyId)
                ->where('m.deleted_at', null)
                ->where('m.moneda !=', 'ARS')
                ->groupStart()
                    ->where('m.cotizacion <=', 1)
                    ->orWhere('m.cotizacion', null)
                ->groupEnd()
                ->orderBy('m.fecha', 'ASC')
                ->orderBy('m.id', 'ASC');

            foreach ($builder->get()->getResultArray() as $row) {
                $rows[] = [
                    'movimiento_tipo' => $def['tipo'],
                    'movimiento_id' => (int) $row['id'],
                    'viaje_id' => (int) $row['viaje_id'],
                    'viaje_numero' => $row['viaje_numero'] === null ? null : (int) $row['viaje_numero'],
                    'viaje_estado' => (string) ($row['viaje_estado'] ?? ''),
                    'sucursal_id' => $row['sucursal_id'] === null ? null : (int) $row['sucursal_id'],
                    'fecha' => $row['fecha'],
                    'importe' => $row['importe'] === null ? null : (float) $row['importe'],
                    'moneda' => (string) ($row['moneda'] ?? ''),
                    'cotizacion' => $row['cotizacion'] === null ? null : (float) $row['cotizacion'],
                    'importe_ars' => $row['importe_ars'] === null ? null : (float) $row['importe_ars'],
                    'created_at' => $row['created_at'],
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['fecha'], (string) $b['fecha']) ?: ($a['movimiento_id'] <=> $b['movimiento_id']));

        return $rows;
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
    private function updateMovement(string $table, string $type, int $tripId, int $companyId, int $movementId, int $actorUserId, array $changes, ?string $changeReason = null): bool
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

        $after = array_merge($before, $changes);
        $reason = $this->normalizeChangeReason($changeReason);
        if ($reason !== null && $this->isMonetaryChange($before, $after)) {
            $after['motivo_cambio'] = $reason;
        }

        $this->auditMovement($companyId, (int) $before['sucursal_id'], $tripId, $type, $movementId, 'EDICION', $before, $after, $actorUserId, $now);

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

    /** @return array{importe:?string,moneda:string,cotizacion:string,importe_ars:?string} */
    private function fuelMoneyColumns(TripFuelLoad $load): array
    {
        $money = $load->money();
        if ($money === null) {
            // Sin precio todavía: se completa después desde «Combustible sin precio».
            return ['importe' => null, 'moneda' => 'ARS', 'cotizacion' => $this->decimal(1.0, 6), 'importe_ars' => null];
        }

        return $this->moneyColumns($money);
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

    private function normalizeChangeReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            return null;
        }
        if (mb_strlen($reason) > 500) {
            throw new RuntimeException('El motivo del cambio monetario no puede superar los 500 caracteres.');
        }

        return $reason;
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function isMonetaryChange(array $before, array $after): bool
    {
        foreach (['importe', 'moneda', 'cotizacion', 'importe_ars'] as $field) {
            $b = $before[$field] ?? null;
            $a = $after[$field] ?? null;
            if ($b === $a) {
                continue;
            }
            if (is_numeric($b) && is_numeric($a) && abs((float) $b - (float) $a) <= 0.00005) {
                continue;
            }
            return true;
        }

        return false;
    }

    private function decimal(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }
}
