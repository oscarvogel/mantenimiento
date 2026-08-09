<?php

declare(strict_types=1);

namespace App\Infrastructure\Assets;

use App\Application\Assets\Port\BranchScope;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterBranchScope implements BranchScope
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
