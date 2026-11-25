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

    public function activeBranches(int $companyId, ?array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }
        $query = $this->database->table('sucursales')
            ->select('id, nombre')
            ->where('empresa_id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre', 'ASC');
        if ($branchIds !== null) {
            $query->whereIn('id', $branchIds);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['nombre'],
        ], $query->get()->getResultArray());
    }
}
