<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\EquipmentRepository;
use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentType;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use RuntimeException;

final class CodeIgniterEquipmentRepository implements EquipmentRepository
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function codeExists(int $companyId, string $code): bool
    {
        return $this->database->table('equipos')
            ->where('empresa_id', $companyId)
            ->where('codigo', $code)
            ->where('deleted_at', null)
            ->countAllResults() > 0;
    }

    public function add(Equipment $equipment, int $actorUserId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->database->table('equipos')->insert([
            'empresa_id'       => $equipment->companyId(),
            'sucursal_id'      => $equipment->branchId(),
            'tipo_equipo_id'   => $equipment->type()->id(),
            'codigo'           => $equipment->code(),
            'patente'          => $equipment->plate(),
            'marca_id'         => $equipment->brandId(),
            'modelo_id'        => $equipment->modelId(),
            'anio'             => $equipment->year(),
            'chasis'           => $equipment->chassis(),
            'motor'            => $equipment->engine(),
            'km_actual'        => $equipment->currentKilometers(),
            'horas_actuales'   => $equipment->currentHours(),
            'estado'           => $equipment->status(),
            'fecha_alta'       => $equipment->registeredAt()->format('Y-m-d'),
            'fecha_baja'       => $equipment->decommissionedAt()?->format('Y-m-d'),
            'observaciones'    => $equipment->notes(),
            'created_at'       => $now,
            'updated_at'       => $now,
            'created_by'       => $actorUserId,
            'updated_by'       => $actorUserId,
        ]);

        $equipmentId = (int) $this->database->insertID();
        if ($equipmentId <= 0) {
            throw new RuntimeException('No se pudo crear el equipo.');
        }

        return $equipmentId;
    }

    public function findForUpdate(int $equipmentId, int $companyId): ?Equipment
    {
        $row = $this->database->query(
            'SELECT e.id, e.empresa_id, e.sucursal_id, e.tipo_equipo_id, e.codigo, e.patente, '
            . 'e.marca_id, e.modelo_id, e.anio, e.chasis, e.motor, '
            . 'e.km_actual, e.horas_actuales, e.estado, e.fecha_alta, e.fecha_baja, e.observaciones, '
            . 't.nombre tipo_nombre, t.controla_km, t.controla_horas '
            . 'FROM equipos e INNER JOIN tipos_equipo t ON t.id = e.tipo_equipo_id '
            . 'WHERE e.id = ? AND e.empresa_id = ? AND e.deleted_at IS NULL FOR UPDATE',
            [$equipmentId, $companyId],
        )->getRowArray();
        if ($row === null) {
            return null;
        }

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

    public function updateCurrentUsage(Equipment $equipment, int $actorUserId): void
    {
        $equipmentId = $equipment->id();
        if ($equipmentId === null) {
            throw new RuntimeException('No se puede actualizar el uso de un equipo sin identidad.');
        }

        $this->database->table('equipos')
            ->where('id', $equipmentId)
            ->where('empresa_id', $equipment->companyId())
            ->where('sucursal_id', $equipment->branchId())
            ->where('deleted_at', null)
            ->update([
                'km_actual'      => $equipment->currentKilometers(),
                'horas_actuales' => $equipment->currentHours(),
                'updated_at'     => date('Y-m-d H:i:s'),
                'updated_by'     => $actorUserId,
            ]);
    }
}
