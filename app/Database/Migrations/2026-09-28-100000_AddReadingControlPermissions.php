<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddReadingControlPermissions extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $permissions = [
            'lecturas.controlar' => 'Controlar lecturas de kilometraje y reclamar por WhatsApp',
        ];

        foreach ($permissions as $key => $description) {
            if (! $this->db->table('permisos')->where('clave', $key)->countAllResults()) {
                $this->db->table('permisos')->insert([
                    'clave' => $key,
                    'descripcion' => $description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $rows = $this->db->table('permisos')->select('id, clave')->whereIn('clave', array_keys($permissions))->get()->getResultArray();
        $ids = array_column($rows, 'id', 'clave');

        $roles = ['Administrador', 'Responsable de mantenimiento'];
        foreach ($roles as $roleName) {
            $role = $this->db->table('roles')->select('id')->where('nombre', $roleName)->get()->getRowArray();
            if ($role === null) {
                continue;
            }
            foreach ($permissions as $key => $description) {
                $permissionId = $ids[$key] ?? null;
                if ($permissionId === null) {
                    continue;
                }
                $relation = ['rol_id' => (int) $role['id'], 'permiso_id' => (int) $permissionId];
                if ($this->db->table('rol_permisos')->where($relation)->countAllResults() === 0) {
                    $this->db->table('rol_permisos')->insert($relation + ['created_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        $permissions = ['lecturas.controlar'];
        $rows = $this->db->table('permisos')->select('id')->whereIn('clave', $permissions)->get()->getResultArray();
        $ids = array_column($rows, 'id');

        if ($ids !== []) {
            $this->db->table('rol_permisos')->whereIn('permiso_id', $ids)->delete();
            $this->db->table('permisos')->whereIn('id', $ids)->delete();
        }
    }
}