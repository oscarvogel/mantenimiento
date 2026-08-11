<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\EquipmentLifecycleRepository;
use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentType;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use RuntimeException;

final class CodeIgniterEquipmentLifecycleRepository implements EquipmentLifecycleRepository
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function findScopedForUpdate(int $companyId, int $equipmentId, ?array $branchIds): ?Equipment
    {
        if ($branchIds === []) {
            return null;
        }

        $sql = 'SELECT e.id, e.empresa_id, e.sucursal_id, e.tipo_equipo_id, e.codigo, e.patente, '
            . 'e.marca_id, e.modelo_id, e.anio, e.chasis, e.motor, '
            . 'e.km_actual, e.horas_actuales, e.estado, e.fecha_alta, e.fecha_baja, e.observaciones, '
            . 't.nombre tipo_nombre, t.controla_km, t.controla_horas '
            . 'FROM equipos e INNER JOIN tipos_equipo t ON t.id = e.tipo_equipo_id '
            . 'WHERE e.empresa_id = ? AND e.id = ? AND e.deleted_at IS NULL';
        $bindings = [$companyId, $equipmentId];
        if ($branchIds !== null) {
            $sql .= ' AND e.sucursal_id IN (' . implode(', ', array_fill(0, count($branchIds), '?')) . ')';
            array_push($bindings, ...$branchIds);
        }
        $sql .= ' FOR UPDATE';

        $row = $this->database->query($sql, $bindings)->getRowArray();

        return $row === null ? null : $this->toDomain($row);
    }

    public function codeExistsExcluding(int $companyId, string $code, int $equipmentId): bool
    {
        return $this->database->table('equipos')
            ->where('empresa_id', $companyId)
            ->where('codigo', $code)
            ->where('id !=', $equipmentId)
            ->where('deleted_at', null)
            ->countAllResults() > 0;
    }

    public function latestTransferAtForUpdate(int $companyId, int $equipmentId): ?DateTimeImmutable
    {
        $row = $this->database->query(
            'SELECT fecha_movimiento FROM equipo_sucursal_historial '
            . 'WHERE empresa_id = ? AND equipo_id = ? '
            . 'ORDER BY fecha_movimiento DESC, id DESC LIMIT 1 FOR UPDATE',
            [$companyId, $equipmentId],
        )->getRowArray();

        return $row === null ? null : new DateTimeImmutable((string) $row['fecha_movimiento']);
    }

    public function hasRecordedUsage(int $companyId, int $equipmentId, string $metric): bool
    {
        if (! in_array($metric, ['kilometraje', 'horometro'], true)) {
            throw new RuntimeException('La métrica de uso consultada no es válida.');
        }

        return $this->database->table('lecturas_equipo')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where($metric . ' IS NOT NULL', null, false)
            ->countAllResults() > 0;
    }

    public function updateProfile(Equipment $equipment, int $actorUserId): void
    {
        $equipmentId = $this->persistedId($equipment);
        $this->database->table('equipos')
            ->where('empresa_id', $equipment->companyId())
            ->where('id', $equipmentId)
            ->where('sucursal_id', $equipment->branchId())
            ->where('deleted_at', null)
            ->update([
                'codigo'        => $equipment->code(),
                'tipo_equipo_id'=> $equipment->type()->id(),
                'patente'       => $equipment->plate(),
                'observaciones' => $equipment->notes(),
                'fecha_alta'    => $equipment->registeredAt()->format('Y-m-d'),
                'marca_id'      => $equipment->brandId(),
                'modelo_id'     => $equipment->modelId(),
                'anio'          => $equipment->year(),
                'chasis'        => $equipment->chassis(),
                'motor'         => $equipment->engine(),
                'updated_at'    => date('Y-m-d H:i:s'),
                'updated_by'    => $actorUserId,
            ]);
        // MySQL reports zero affected rows for an idempotent update. The row was
        // already loaded and locked with the same tenant/branch scope.
    }

    public function decommission(Equipment $equipment, int $actorUserId): void
    {
        $equipmentId = $this->persistedId($equipment);
        $this->database->table('equipos')
            ->where('empresa_id', $equipment->companyId())
            ->where('id', $equipmentId)
            ->where('sucursal_id', $equipment->branchId())
            ->where('estado', Equipment::ACTIVE)
            ->where('deleted_at', null)
            ->update([
                'estado'     => $equipment->status(),
                'fecha_baja' => $equipment->decommissionedAt()?->format('Y-m-d'),
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $actorUserId,
            ]);
        $this->assertOneRowChanged('dar de baja el equipo');
    }

    public function transfer(
        Equipment $equipment,
        int $originBranchId,
        DateTimeImmutable $occurredAt,
        string $reason,
        int $actorUserId,
    ): void {
        $equipmentId = $this->persistedId($equipment);
        $now = date('Y-m-d H:i:s');
        $this->database->table('equipos')
            ->where('empresa_id', $equipment->companyId())
            ->where('id', $equipmentId)
            ->where('sucursal_id', $originBranchId)
            ->where('estado', Equipment::ACTIVE)
            ->where('deleted_at', null)
            ->update([
                'sucursal_id' => $equipment->branchId(),
                'updated_at'  => $now,
                'updated_by'  => $actorUserId,
            ]);
        $this->assertOneRowChanged('trasladar el equipo');

        $this->database->table('equipo_sucursal_historial')->insert([
            'empresa_id'          => $equipment->companyId(),
            'equipo_id'           => $equipmentId,
            'sucursal_origen_id'  => $originBranchId,
            'sucursal_destino_id' => $equipment->branchId(),
            'fecha_movimiento'    => $occurredAt->format('Y-m-d H:i:s'),
            'usuario_id'          => $actorUserId,
            'motivo'              => $reason,
            'created_at'          => $now,
        ]);
        if ((int) $this->database->insertID() <= 0) {
            throw new RuntimeException('No se pudo registrar el historial del traslado.');
        }
    }

    /** @param array<string, mixed> $row */
    private function toDomain(array $row): Equipment
    {
        return Equipment::reconstitute(
            (int) $row['id'],
            (int) $row['empresa_id'],
            (int) $row['sucursal_id'],
            new EquipmentType(
                (int) $row['tipo_equipo_id'],
                (string) $row['tipo_nombre'],
                (bool) $row['controla_km'],
                (bool) $row['controla_horas'],
            ),
            (string) $row['codigo'],
            $row['patente'] === null ? null : (string) $row['patente'],
            (string) $row['estado'],
            new DateTimeImmutable((string) $row['fecha_alta']),
            $row['fecha_baja'] === null ? null : new DateTimeImmutable((string) $row['fecha_baja']),
            $row['observaciones'] === null ? null : (string) $row['observaciones'],
            $row['km_actual'] === null ? null : (int) $row['km_actual'],
            $row['horas_actuales'] === null ? null : (string) $row['horas_actuales'],
            $row['marca_id'] === null ? null : (int) $row['marca_id'],
            $row['modelo_id'] === null ? null : (int) $row['modelo_id'],
            $row['anio'] === null ? null : (int) $row['anio'],
            $row['chasis'] === null ? null : (string) $row['chasis'],
            $row['motor'] === null ? null : (string) $row['motor'],
        );
    }

    private function persistedId(Equipment $equipment): int
    {
        $id = $equipment->id();
        if ($id === null) {
            throw new RuntimeException('No se puede modificar un equipo sin identidad.');
        }

        return $id;
    }

    private function assertOneRowChanged(string $operation): void
    {
        if ($this->database->affectedRows() !== 1) {
            throw new RuntimeException("No se pudo {$operation} de forma consistente.");
        }
    }
}
