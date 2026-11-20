<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\TripLegCancellationStore;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

final class CodeIgniterTripLegCancellationStore implements TripLegCancellationStore
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function state(int $companyId, int $tripId, int $legId): ?array
    {
        $row = $this->database->table('viaje_operativo_tramos')
            ->select('id, anulado_at')
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->where('id', $legId)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        $assigned = 0;
        foreach (['viaje_operativo_combustible', 'viaje_operativo_gastos'] as $table) {
            $assigned += $this->database->table($table)
                ->where('empresa_id', $companyId)
                ->where('viaje_id', $tripId)
                ->where('tramo_id', $legId)
                ->where('deleted_at', null)
                ->countAllResults();
        }

        return [
            'cancelled' => $row['anulado_at'] !== null,
            'assigned_costs' => $assigned,
        ];
    }

    public function cancel(int $companyId, int $branchId, int $tripId, int $legId, string $reason, int $actorUserId): void
    {
        $before = $this->database->table('viaje_operativo_tramos')
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->where('id', $legId)
            ->where('anulado_at', null)
            ->get()->getRowArray();
        if ($before === null) {
            throw new RuntimeException('No se pudo anular el tramo del viaje.');
        }

        $now = date('Y-m-d H:i:s');
        $changes = [
            'anulado_at' => $now,
            'anulado_por' => $actorUserId,
            'motivo_anulacion' => $reason,
            'updated_at' => $now,
            'updated_by' => $actorUserId,
        ];
        $updated = $this->database->table('viaje_operativo_tramos')
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->where('id', $legId)
            ->where('anulado_at', null)
            ->update($changes);
        if (! $updated || $this->database->affectedRows() !== 1) {
            throw new RuntimeException('No se pudo anular el tramo del viaje.');
        }

        $inserted = $this->database->table('viaje_operativo_movimientos_auditoria')->insert([
            'empresa_id' => $companyId,
            'sucursal_id' => $branchId,
            'viaje_id' => $tripId,
            'movimiento_tipo' => 'TRAMO',
            'movimiento_id' => $legId,
            'accion' => 'ANULACION',
            'datos_anteriores' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'datos_nuevos' => json_encode(array_merge($before, $changes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'usuario_id' => $actorUserId,
            'created_at' => $now,
        ]);
        if (! $inserted) {
            throw new RuntimeException('No se pudo registrar la auditoría de la anulación del tramo.');
        }
    }
}
