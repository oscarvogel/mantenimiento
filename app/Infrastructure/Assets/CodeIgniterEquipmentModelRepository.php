<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\EquipmentModelRepository;
use App\Domain\Assets\EquipmentModel;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

final class CodeIgniterEquipmentModelRepository implements EquipmentModelRepository
{
    public function __construct(private readonly BaseConnection $database) {}

    public function nameExists(int $companyId, int $brandId, int $typeId, string $name, ?int $excludingId = null): bool
    {
        $builder = $this->database->table('modelos')->where('empresa_id', $companyId)->where('marca_id', $brandId)
            ->where('tipo_equipo_id', $typeId)->where('nombre', $name);
        if ($excludingId !== null) {
            $builder->where('id !=', $excludingId);
        }
        return $builder->countAllResults() > 0;
    }

    public function add(EquipmentModel $model, int $actorUserId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->database->table('modelos')->insert([
            'empresa_id' => $model->companyId(), 'marca_id' => $model->brandId(), 'tipo_equipo_id' => $model->equipmentTypeId(),
            'nombre' => $model->name(), 'activo' => 1, 'created_at' => $now, 'updated_at' => $now,
            'created_by' => $actorUserId, 'updated_by' => $actorUserId,
        ]);
        $id = (int) $this->database->insertID();
        if ($id <= 0) {
            throw new RuntimeException('No se pudo crear el modelo.');
        }
        return $id;
    }

    public function findForUpdate(int $companyId, int $modelId): ?EquipmentModel
    {
        $row = $this->database->query('SELECT id, empresa_id, marca_id, tipo_equipo_id, nombre, activo FROM modelos WHERE empresa_id = ? AND id = ? FOR UPDATE', [$companyId, $modelId])->getRowArray();
        return $row === null ? null : $this->hydrate($row);
    }

    public function save(EquipmentModel $model, int $actorUserId): void
    {
        $id = $model->id();
        if ($id === null) {
            throw new RuntimeException('No se puede actualizar un modelo sin identidad.');
        }
        $this->database->table('modelos')->where('empresa_id', $model->companyId())->where('id', $id)->update([
            'nombre' => $model->name(), 'activo' => $model->isActive() ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $actorUserId,
        ]);
    }

    public function findActiveById(int $companyId, int $modelId): ?EquipmentModel
    {
        $row = $this->database->table('modelos')->select('id, empresa_id, marca_id, tipo_equipo_id, nombre, activo')
            ->where('empresa_id', $companyId)->where('id', $modelId)->where('activo', 1)->get()->getRowArray();
        return $row === null ? null : $this->hydrate($row);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): EquipmentModel
    {
        return EquipmentModel::reconstitute(
            (int) $row['id'], (int) $row['empresa_id'], (int) $row['marca_id'], (int) $row['tipo_equipo_id'],
            (string) $row['nombre'], (bool) $row['activo'],
        );
    }
}
