<?php

declare(strict_types=1);

namespace App\Infrastructure\Employees;

use CodeIgniter\Database\BaseConnection;

final class CodeIgniterDriverAssignmentPreviewCatalog
{
    public function __construct(private readonly BaseConnection $database) {}

    /** @return list<array{id:int,patente:string|null}> */
    public function equipments(int $companyId): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'patente' => $row['patente'] === null ? null : (string) $row['patente'],
            ],
            $this->database->table('equipos')
                ->select('id, patente')
                ->where('empresa_id', $companyId)
                ->where('deleted_at', null)
                ->get()->getResultArray(),
        );
    }

    /** @return list<array{id:int,nombre:string,apellido:string,activo:mixed}> */
    public function employees(int $companyId): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'nombre' => (string) $row['nombre'],
                'apellido' => (string) $row['apellido'],
                'activo' => $row['activo'],
            ],
            $this->database->table('empleados')
                ->select('id, nombre, apellido, activo')
                ->where('empresa_id', $companyId)
                ->where('deleted_at', null)
                ->get()->getResultArray(),
        );
    }
}
