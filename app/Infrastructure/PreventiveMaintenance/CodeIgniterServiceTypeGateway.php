<?php

declare(strict_types=1);

namespace App\Infrastructure\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\ServiceTypeGateway;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class CodeIgniterServiceTypeGateway implements ServiceTypeGateway
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function isActive(int $serviceTypeId): bool
    {
        return $this->db->table('tipos_servicio')
            ->where('id', $serviceTypeId)
            ->where('activo', 1)
            ->countAllResults() === 1;
    }
}
