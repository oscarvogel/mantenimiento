<?php

declare(strict_types=1);

namespace App\Infrastructure\Neumaticos;

use App\Application\Neumaticos\NeumaticMasterCatalog;
use App\Application\Neumaticos\Port\NeumaticMasterRepository;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final class CodeIgniterNeumaticMasterRepository implements NeumaticMasterRepository
{
    /** @var array<string, array{table: string, name: string}> */
    private const TABLES = [
        NeumaticMasterCatalog::BRANDS => ['table' => 'neumatico_marcas', 'name' => 'nombre'],
        NeumaticMasterCatalog::MODELS => ['table' => 'neumatico_modelos', 'name' => 'nombre'],
        NeumaticMasterCatalog::COVER_TYPES => ['table' => 'neumatico_tipos_cubierta', 'name' => 'descripcion'],
        NeumaticMasterCatalog::DEPOSITS => ['table' => 'depositos', 'name' => 'nombre'],
    ];

    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function list(string $catalog, int $companyId, ?array $branchIds): array
    {
        $definition = self::definition($catalog);
        $table = $definition['table'];
        if ($catalog === NeumaticMasterCatalog::DEPOSITS && $branchIds === []) {
            return [];
        }

        $query = $this->database->table($table . ' m')
            ->select('m.id, m.' . $definition['name'] . ' AS name, m.activo')
            ->where('m.empresa_id', $companyId)
            ->orderBy('m.' . $definition['name'], 'ASC');
        if ($catalog === NeumaticMasterCatalog::MODELS) {
            $query->select('m.marca_id, b.nombre AS brand_name')
                ->join('neumatico_marcas b', 'b.id = m.marca_id AND b.empresa_id = m.empresa_id', 'inner');
        }
        if ($catalog === NeumaticMasterCatalog::DEPOSITS) {
            $query->select('m.sucursal_id, m.ubicacion, s.nombre AS branch_name')
                ->join('sucursales s', 's.id = m.sucursal_id AND s.empresa_id = m.empresa_id', 'inner');
            if ($branchIds !== null) {
                $query->whereIn('m.sucursal_id', $branchIds);
            }
        }

        return array_map(static function (array $row) use ($catalog): array {
            $item = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'active' => (int) $row['activo'] === 1,
            ];
            if ($catalog === NeumaticMasterCatalog::MODELS) {
                $item['brandId'] = (int) $row['marca_id'];
                $item['brandName'] = (string) $row['brand_name'];
            }
            if ($catalog === NeumaticMasterCatalog::DEPOSITS) {
                $item['branchId'] = (int) $row['sucursal_id'];
                $item['branchName'] = (string) $row['branch_name'];
                $item['location'] = $row['ubicacion'] === null ? '' : (string) $row['ubicacion'];
            }

            return $item;
        }, $query->get()->getResultArray());
    }

    public function nameTaken(string $catalog, int $companyId, string $name, ?int $brandId, ?int $excludeId): bool
    {
        $definition = self::definition($catalog);
        $query = $this->database->table($definition['table'])
            ->where('empresa_id', $companyId)
            ->where('LOWER(' . $definition['name'] . ') = ' . $this->database->escape(mb_strtolower($name)), null, false);
        if ($catalog === NeumaticMasterCatalog::MODELS) {
            $query->where('marca_id', (int) $brandId);
        }
        if ($excludeId !== null) {
            $query->where('id !=', $excludeId);
        }

        return $query->countAllResults() > 0;
    }

    public function create(string $catalog, int $companyId, array $values): int
    {
        $now = date('Y-m-d H:i:s');
        $this->database->table(self::definition($catalog)['table'])->insert($values + [
            'empresa_id' => $companyId,
            'activo' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->database->insertID();
    }

    public function update(string $catalog, int $companyId, ?array $branchIds, int $id, array $values): bool
    {
        if (! $this->exists($catalog, $companyId, $branchIds, $id)) {
            return false;
        }
        $this->scoped($catalog, $companyId, $branchIds, $id)->update($values + ['updated_at' => date('Y-m-d H:i:s')]);

        return true;
    }

    public function setActive(string $catalog, int $companyId, ?array $branchIds, int $id, bool $active): bool
    {
        if (! $this->exists($catalog, $companyId, $branchIds, $id)) {
            return false;
        }
        $this->scoped($catalog, $companyId, $branchIds, $id)->update([
            'activo' => $active ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function exists(string $catalog, int $companyId, ?array $branchIds, int $id): bool
    {
        if ($catalog === NeumaticMasterCatalog::DEPOSITS && $branchIds === []) {
            return false;
        }

        return $this->scoped($catalog, $companyId, $branchIds, $id)->countAllResults() === 1;
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function scoped(string $catalog, int $companyId, ?array $branchIds, int $id): BaseBuilder
    {
        $query = $this->database->table(self::definition($catalog)['table'])
            ->where('empresa_id', $companyId)
            ->where('id', $id);
        if ($catalog === NeumaticMasterCatalog::DEPOSITS && $branchIds !== null) {
            $query->whereIn('sucursal_id', $branchIds);
        }

        return $query;
    }

    /**
     * @return array{table: string, name: string}
     */
    private static function definition(string $catalog): array
    {
        return self::TABLES[$catalog] ?? throw new DomainException('El maestro elegido no existe.');
    }
}
