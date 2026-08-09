<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\EquipmentRelationRepository;
use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentRelation;
use App\Domain\Assets\EquipmentType;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use RuntimeException;

final class CodeIgniterEquipmentRelationRepository implements EquipmentRelationRepository
{
    public function __construct(private readonly BaseConnection $database) {}

    public function findEquipmentForUpdate(int $companyId, int $equipmentId, ?array $branchIds): ?Equipment
    {
        if ($branchIds === []) {
            return null;
        }
        $sql = 'SELECT e.*, te.nombre tipo_nombre, te.controla_km, te.controla_horas FROM equipos e '
            . 'INNER JOIN tipos_equipo te ON te.id = e.tipo_equipo_id '
            . 'WHERE e.empresa_id = ? AND e.id = ? AND e.deleted_at IS NULL';
        $bindings = [$companyId, $equipmentId];
        if ($branchIds !== null) {
            $sql .= ' AND e.sucursal_id IN (' . implode(', ', array_fill(0, count($branchIds), '?')) . ')';
            array_push($bindings, ...$branchIds);
        }
        $sql .= ' FOR UPDATE';
        $row = $this->database->query($sql, $bindings)->getRowArray();
        return $row === null ? null : $this->equipment($row);
    }

    public function hasActiveIncompatibleRelation(int $companyId, int $relatedEquipmentId, string $type): bool
    {
        $row = $this->database->query(
            'SELECT id FROM equipo_relaciones WHERE empresa_id = ? AND equipo_relacionado_id = ? AND tipo_relacion = ? AND hasta IS NULL LIMIT 1 FOR UPDATE',
            [$companyId, $relatedEquipmentId, $type],
        )->getRowArray();
        return $row !== null;
    }

    public function add(EquipmentRelation $relation): int
    {
        $now = date('Y-m-d H:i:s');
        $this->database->table('equipo_relaciones')->insert([
            'empresa_id' => $relation->companyId(),
            'equipo_principal_id' => $relation->principalEquipmentId(),
            'equipo_relacionado_id' => $relation->relatedEquipmentId(),
            'tipo_relacion' => $relation->type(),
            'desde' => $relation->startedAt()->format('Y-m-d H:i:s'),
            'hasta' => null,
            'usuario_id' => $relation->createdBy(),
            'finalizado_por' => null,
            'observaciones' => $relation->notes(),
            'observaciones_fin' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $this->database->insertID();
        if ($id <= 0) {
            throw new RuntimeException('No se pudo crear la relación entre equipos.');
        }
        return $id;
    }

    public function findRelationForUpdate(int $companyId, int $relationId, ?array $branchIds): ?EquipmentRelation
    {
        if ($branchIds === []) {
            return null;
        }
        $sql = 'SELECT r.* FROM equipo_relaciones r '
            . 'INNER JOIN equipos ep ON ep.id = r.equipo_principal_id AND ep.empresa_id = r.empresa_id '
            . 'INNER JOIN equipos er ON er.id = r.equipo_relacionado_id AND er.empresa_id = r.empresa_id '
            . 'WHERE r.empresa_id = ? AND r.id = ?';
        $bindings = [$companyId, $relationId];
        if ($branchIds !== null) {
            $placeholders = implode(', ', array_fill(0, count($branchIds), '?'));
            $sql .= " AND ep.sucursal_id IN ({$placeholders}) AND er.sucursal_id IN ({$placeholders})";
            array_push($bindings, ...$branchIds, ...$branchIds);
        }
        $sql .= ' FOR UPDATE';
        $row = $this->database->query($sql, $bindings)->getRowArray();
        if ($row === null) {
            return null;
        }
        return EquipmentRelation::reconstitute(
            (int) $row['id'], (int) $row['empresa_id'], (int) $row['equipo_principal_id'], (int) $row['equipo_relacionado_id'],
            (string) $row['tipo_relacion'], new DateTimeImmutable((string) $row['desde']),
            $row['hasta'] === null ? null : new DateTimeImmutable((string) $row['hasta']),
            (int) $row['usuario_id'], $row['finalizado_por'] === null ? null : (int) $row['finalizado_por'],
            $row['observaciones'] === null ? null : (string) $row['observaciones'],
            $row['observaciones_fin'] === null ? null : (string) $row['observaciones_fin'],
        );
    }

    public function finish(EquipmentRelation $relation): void
    {
        $id = $relation->id();
        if ($id === null || $relation->endedAt() === null) {
            throw new RuntimeException('La relación debe estar persistida y finalizada antes de guardarse.');
        }
        $this->database->table('equipo_relaciones')->where('empresa_id', $relation->companyId())->where('id', $id)->where('hasta', null)->update([
            'hasta' => $relation->endedAt()->format('Y-m-d H:i:s'),
            'finalizado_por' => $relation->endedBy(),
            'observaciones_fin' => $relation->endingNotes(),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($this->database->affectedRows() !== 1) {
            throw new RuntimeException('No se pudo finalizar la relación de forma consistente.');
        }
    }

    /** @param array<string,mixed> $row */
    private function equipment(array $row): Equipment
    {
        return Equipment::reconstitute(
            (int) $row['id'], (int) $row['empresa_id'], (int) $row['sucursal_id'],
            new EquipmentType((int) $row['tipo_equipo_id'], (string) $row['tipo_nombre'], (bool) $row['controla_km'], (bool) $row['controla_horas']),
            (string) $row['codigo'], $row['patente'] === null ? null : (string) $row['patente'], (string) $row['estado'],
            new DateTimeImmutable((string) $row['fecha_alta']), $row['fecha_baja'] === null ? null : new DateTimeImmutable((string) $row['fecha_baja']),
            $row['observaciones'] === null ? null : (string) $row['observaciones'], $row['km_actual'] === null ? null : (int) $row['km_actual'],
            $row['horas_actuales'] === null ? null : (string) $row['horas_actuales'],
            $row['marca_id'] === null ? null : (int) $row['marca_id'], $row['modelo_id'] === null ? null : (int) $row['modelo_id'],
            $row['anio'] === null ? null : (int) $row['anio'], $row['chasis'] === null ? null : (string) $row['chasis'],
            $row['motor'] === null ? null : (string) $row['motor'],
        );
    }
}
