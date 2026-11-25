<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Permisos del contexto Neumáticos. Los casos de uso ya los exigen.
 *
 * `neumaticos.ver` es de lectura: se asigna a los roles que consultan.
 * `neumaticos.operar` da de alta, corrige, cambia estados y mueve depósito:
 * se asigna a Administrador y Responsable de mantenimiento.
 */
final class AddNeumaticosPermissions extends Migration
{
    /** @var array<string, array{description: string, roles: list<string>}> */
    private const PERMISSIONS = [
        'neumaticos.ver' => [
            'description' => 'Consultar el padrón, la ficha y el rendimiento de neumáticos',
            'roles' => ['Administrador', 'Responsable de mantenimiento', 'Tecnico u operador', 'Consulta', 'Gerencial'],
        ],
        'neumaticos.operar' => [
            'description' => 'Dar de alta neumáticos, cambiar su estado y registrar movimientos de depósito',
            'roles' => ['Administrador', 'Responsable de mantenimiento'],
        ],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        foreach (self::PERMISSIONS as $key => $definition) {
            if (! $this->db->table('permisos')->where('clave', $key)->countAllResults()) {
                $this->db->table('permisos')->insert([
                    'clave' => $key,
                    'descripcion' => $definition['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $permission = $this->db->table('permisos')->select('id')->where('clave', $key)->get()->getRowArray();
            if ($permission === null) {
                continue;
            }

            $roles = $this->db->table('roles')->select('id')->whereIn('nombre', $definition['roles'])->get()->getResultArray();
            foreach ($roles as $role) {
                $relation = ['rol_id' => (int) $role['id'], 'permiso_id' => (int) $permission['id']];
                if (! $this->db->table('rol_permisos')->where($relation)->countAllResults()) {
                    $this->db->table('rol_permisos')->insert($relation + ['created_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        $rows = $this->db->table('permisos')->select('id')->whereIn('clave', array_keys(self::PERMISSIONS))->get()->getResultArray();
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        if ($ids === []) {
            return;
        }

        $this->db->table('rol_permisos')->whereIn('permiso_id', $ids)->delete();
        $this->db->table('permisos')->whereIn('id', $ids)->delete();
    }
}
