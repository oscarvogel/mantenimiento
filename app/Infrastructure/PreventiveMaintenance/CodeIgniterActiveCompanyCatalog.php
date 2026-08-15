<?php

declare(strict_types=1);

namespace App\Infrastructure\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\ActiveCompanyCatalog;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class CodeIgniterActiveCompanyCatalog implements ActiveCompanyCatalog
{
    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /** @return list<int> */
    public function listActiveCompanyIds(): array
    {
        $database = $this->db ?? Database::connect();

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $database->table('empresas')
                ->select('id')
                ->where('estado', 1)
                ->where('deleted_at', null)
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray(),
        );
    }
}
