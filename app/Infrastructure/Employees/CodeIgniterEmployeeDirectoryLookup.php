<?php

declare(strict_types=1);

namespace App\Infrastructure\Employees;

use App\Application\Employees\Port\EmployeeDirectoryLookup;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterEmployeeDirectoryLookup implements EmployeeDirectoryLookup
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function driver(int $companyId, int $employeeId): ?array
    {
        $row = $this->database->table('empleados')
            ->select('id, nombre, apellido, activo')
            ->where('empresa_id', $companyId)
            ->where('id', $employeeId)
            ->where('deleted_at', null)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'label' => trim((string) $row['nombre'] . ' ' . (string) ($row['apellido'] ?? '')),
            'active' => (bool) $row['activo'],
        ];
    }
}
