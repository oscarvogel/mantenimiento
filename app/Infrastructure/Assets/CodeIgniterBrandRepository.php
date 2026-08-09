<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\BrandRepository;
use App\Domain\Assets\Brand;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

final class CodeIgniterBrandRepository implements BrandRepository
{
    public function __construct(private readonly BaseConnection $database) {}

    public function nameExists(int $companyId, string $name, ?int $excludingId = null): bool
    {
        $builder = $this->database->table('marcas')->where('empresa_id', $companyId)->where('nombre', $name);
        if ($excludingId !== null) {
            $builder->where('id !=', $excludingId);
        }
        return $builder->countAllResults() > 0;
    }

    public function add(Brand $brand, int $actorUserId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->database->table('marcas')->insert([
            'empresa_id' => $brand->companyId(), 'nombre' => $brand->name(), 'activo' => 1,
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $actorUserId, 'updated_by' => $actorUserId,
        ]);
        $id = (int) $this->database->insertID();
        if ($id <= 0) {
            throw new RuntimeException('No se pudo crear la marca.');
        }
        return $id;
    }

    public function findForUpdate(int $companyId, int $brandId): ?Brand
    {
        $row = $this->database->query('SELECT id, empresa_id, nombre, activo FROM marcas WHERE empresa_id = ? AND id = ? FOR UPDATE', [$companyId, $brandId])->getRowArray();
        return $row === null ? null : $this->hydrate($row);
    }

    public function save(Brand $brand, int $actorUserId): void
    {
        $id = $brand->id();
        if ($id === null) {
            throw new RuntimeException('No se puede actualizar una marca sin identidad.');
        }
        $this->database->table('marcas')->where('empresa_id', $brand->companyId())->where('id', $id)->update([
            'nombre' => $brand->name(), 'activo' => $brand->isActive() ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $actorUserId,
        ]);
    }

    public function findActiveById(int $companyId, int $brandId): ?Brand
    {
        $row = $this->database->table('marcas')->select('id, empresa_id, nombre, activo')
            ->where('empresa_id', $companyId)->where('id', $brandId)->where('activo', 1)->get()->getRowArray();
        return $row === null ? null : $this->hydrate($row);
    }

    public function hasActiveModels(int $companyId, int $brandId): bool
    {
        return $this->database->table('modelos')->where('empresa_id', $companyId)->where('marca_id', $brandId)->where('activo', 1)->countAllResults() > 0;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Brand
    {
        return Brand::reconstitute((int) $row['id'], (int) $row['empresa_id'], (string) $row['nombre'], (bool) $row['activo']);
    }
}
