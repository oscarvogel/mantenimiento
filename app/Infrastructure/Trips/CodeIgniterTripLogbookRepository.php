<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\TripLogbookRepository;
use App\Domain\Trips\TripLogbookEntry;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

final class CodeIgniterTripLogbookRepository implements TripLogbookRepository
{
    private const TABLE = 'viaje_operativo_diario_bordo';
    private const AUDIT_TYPE = 'DIARIO_BORDO';

    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function nextOrder(int $tripId, int $companyId): int
    {
        $row = $this->database->table(self::TABLE)
            ->selectMax('orden')
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->where('deleted_at', null)
            ->get()->getRowArray();

        return (int) ($row['orden'] ?? 0) + 1;
    }

    public function add(int $tripId, int $companyId, int $branchId, TripLogbookEntry $entry, int $actorUserId): int
    {
        $now = date('Y-m-d H:i:s');
        if (! $this->database->table(self::TABLE)->insert($this->columns($entry) + [
            'viaje_id' => $tripId,
            'empresa_id' => $companyId,
            'sucursal_id' => $branchId,
            'created_at' => $now,
            'created_by' => $actorUserId,
        ])) {
            throw new RuntimeException('No se pudo guardar la etapa del diario de bordo.');
        }

        $id = (int) $this->database->insertID();
        if ($id <= 0) {
            throw new RuntimeException('No se pudo guardar la etapa del diario de bordo.');
        }

        return $id;
    }

    public function update(int $tripId, int $companyId, int $entryId, TripLogbookEntry $entry, int $actorUserId): bool
    {
        $before = $this->activeEntry($tripId, $companyId, $entryId);
        if ($before === null) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $changes = $this->columns($entry, false) + ['updated_at' => $now, 'updated_by' => $actorUserId];
        $updated = $this->database->table(self::TABLE)
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->where('id', $entryId)
            ->where('deleted_at', null)
            ->update($changes);
        if (! $updated) {
            throw new RuntimeException('No se pudieron guardar los cambios del diario de bordo.');
        }

        $this->audit($companyId, (int) $before['sucursal_id'], $tripId, $entryId, 'EDICION', $before, array_merge($before, $changes), $actorUserId, $now);

        return true;
    }

    public function softDelete(int $tripId, int $companyId, int $entryId, int $actorUserId): bool
    {
        $before = $this->activeEntry($tripId, $companyId, $entryId);
        if ($before === null) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $changes = ['updated_at' => $now, 'updated_by' => $actorUserId, 'deleted_at' => $now, 'deleted_by' => $actorUserId];
        $updated = $this->database->table(self::TABLE)
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->where('id', $entryId)
            ->where('deleted_at', null)
            ->update($changes);
        if (! $updated) {
            throw new RuntimeException('No se pudo quitar la etapa del diario de bordo.');
        }

        $this->audit($companyId, (int) $before['sucursal_id'], $tripId, $entryId, 'BAJA_LOGICA', $before, array_merge($before, $changes), $actorUserId, $now);

        return true;
    }

    public function linkedEarningId(int $tripId, int $companyId, int $entryId): ?int
    {
        $row = $this->database->table('viaje_operativo_otros_haberes')
            ->select('id')
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->where('diario_bordo_id', $entryId)
            ->where('deleted_at', null)
            ->orderBy('id', 'DESC')
            ->get()->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }

    /** @return array<string,mixed> */
    private function columns(TripLogbookEntry $entry, bool $includeOrder = true): array
    {
        $columns = [
            'tipo' => $entry->type(),
            'ubicacion' => mb_substr($entry->location(), 0, 200),
            'empleado_id' => $entry->employeeId(),
            'concepto_id' => $entry->conceptId(),
            'llegada' => $entry->arrivedAt()->format('Y-m-d H:i:s'),
            'salida' => $entry->departedAt()?->format('Y-m-d H:i:s'),
            'horas_libres' => $entry->freeHours(),
            'dias_calculados' => $entry->calculatedDays() === null ? null : $this->decimal($entry->calculatedDays(), 2),
            'dias_ajustados' => $entry->adjustedDays() === null ? null : $this->decimal($entry->adjustedDays(), 2),
            'motivo_ajuste' => $entry->adjustmentReason() === null ? null : mb_substr($entry->adjustmentReason(), 0, 500),
            'observaciones' => $entry->notes() === null ? null : mb_substr($entry->notes(), 0, 500),
        ];

        if ($includeOrder) {
            $columns['orden'] = $entry->order();
        }

        return $columns;
    }

    /** @return array<string,mixed>|null */
    private function activeEntry(int $tripId, int $companyId, int $entryId): ?array
    {
        $row = $this->database->table(self::TABLE)
            ->where('empresa_id', $companyId)
            ->where('viaje_id', $tripId)
            ->where('id', $entryId)
            ->where('deleted_at', null)
            ->get()->getRowArray();

        return $row === null ? null : $row;
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $after */
    private function audit(int $companyId, int $branchId, int $tripId, int $entryId, string $action, ?array $before, ?array $after, int $actorUserId, string $at): void
    {
        $inserted = $this->database->table('viaje_operativo_movimientos_auditoria')->insert([
            'empresa_id' => $companyId,
            'sucursal_id' => $branchId,
            'viaje_id' => $tripId,
            'movimiento_tipo' => self::AUDIT_TYPE,
            'movimiento_id' => $entryId,
            'accion' => $action,
            'datos_anteriores' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'datos_nuevos' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'usuario_id' => $actorUserId,
            'created_at' => $at,
        ]);
        if (! $inserted) {
            throw new RuntimeException('No se pudo registrar la auditoría del diario de bordo.');
        }
    }

    private function decimal(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }
}
