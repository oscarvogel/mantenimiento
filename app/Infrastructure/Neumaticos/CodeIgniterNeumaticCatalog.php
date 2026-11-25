<?php

declare(strict_types=1);

namespace App\Infrastructure\Neumaticos;

use App\Application\Neumaticos\Port\NeumaticCatalog;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterNeumaticCatalog implements NeumaticCatalog
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function isActiveBrand(int $companyId, int $brandId): bool
    {
        return $this->active('neumatico_marcas', $companyId, $brandId);
    }

    public function isActiveModelOfBrand(int $companyId, int $modelId, int $brandId): bool
    {
        return $this->isActiveBrand($companyId, $brandId)
            && $this->database->table('neumatico_modelos')
                ->where('empresa_id', $companyId)
                ->where('id', $modelId)
                ->where('marca_id', $brandId)
                ->where('activo', 1)
                ->countAllResults() === 1;
    }

    public function isActiveCoverType(int $companyId, int $coverTypeId): bool
    {
        return $this->active('neumatico_tipos_cubierta', $companyId, $coverTypeId);
    }

    private function active(string $table, int $companyId, int $id): bool
    {
        return $this->database->table($table)
            ->where('empresa_id', $companyId)
            ->where('id', $id)
            ->where('activo', 1)
            ->countAllResults() === 1;
    }
}
