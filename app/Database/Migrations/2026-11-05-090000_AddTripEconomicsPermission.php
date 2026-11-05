<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Permite separar el económico del tablero de viajes del resto de la operación.
 *
 * Solo los roles de gerencia y administración reciben el permiso; el resto
 * puede seguir viendo viajes, estados y recorridos sin los importes.
 */
final class AddTripEconomicsPermission extends Migration
{
    private const PERMISSION = 'viajes.economico.ver';

    private const ROLES = ['Administrador', 'Gerencial'];

    /** Acceso de lectura necesario para que Gerencial pueda entrar al tablero. */
    private const EXTRA_ROLE_PERMISSIONS = [
        'Gerencial' => ['viajes.ver'],
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        if (! $this->db->table('permisos')->where('clave', self::PERMISSION)->countAllResults()) {
            $this->db->table('permisos')->insert([
                'clave' => self::PERMISSION,
                'descripcion' => 'Ver el económico del período en el tablero de viajes',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permission = $this->db->table('permisos')->select('id')
            ->where('clave', self::PERMISSION)->get()->getRowArray();
        $permissionId = (int) ($permission['id'] ?? 0);
        if ($permissionId === 0) {
            return;
        }

        foreach ($this->db->table('roles')->select('id')->whereIn('nombre', self::ROLES)->get()->getResultArray() as $role) {
            $relation = ['rol_id' => (int) $role['id'], 'permiso_id' => $permissionId];
            if (! $this->db->table('rol_permisos')->where($relation)->countAllResults()) {
                $this->db->table('rol_permisos')->insert($relation + ['created_at' => $now]);
            }
        }

        foreach (self::EXTRA_ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = $this->db->table('roles')->select('id')->where('nombre', $roleName)->get()->getRowArray();
            if ($role === null) {
                continue;
            }
            foreach ($permissions as $permissionKey) {
                $extra = $this->db->table('permisos')->select('id')->where('clave', $permissionKey)->get()->getRowArray();
                if ($extra === null) {
                    continue;
                }
                $relation = ['rol_id' => (int) $role['id'], 'permiso_id' => (int) $extra['id']];
                if (! $this->db->table('rol_permisos')->where($relation)->countAllResults()) {
                    $this->db->table('rol_permisos')->insert($relation + ['created_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        $rows = $this->db->table('permisos')->select('id')
            ->where('clave', self::PERMISSION)->get()->getResultArray();
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        if ($ids === []) {
            return;
        }

        $this->db->table('rol_permisos')->whereIn('permiso_id', $ids)->delete();
        $this->db->table('permisos')->whereIn('id', $ids)->delete();
    }
}
