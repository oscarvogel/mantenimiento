<?php

declare(strict_types=1);

namespace App\Infrastructure\Neumaticos;

use App\Application\Neumaticos\Port\NeumaticBranchScope;
use CodeIgniter\Database\BaseConnection;

/**
 * Lee `sucursales`, que pertenece a Organización, solo para confirmar que la
 * sucursal es de la empresa y está activa. No escribe.
 */
final class CodeIgniterNeumaticBranchScope implements NeumaticBranchScope
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function isActiveInCompany(int $companyId, int $branchId): bool
    {
        return $this->database->table('sucursales')
            ->where('id', $branchId)
            ->where('empresa_id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->countAllResults() === 1;
    }
}
