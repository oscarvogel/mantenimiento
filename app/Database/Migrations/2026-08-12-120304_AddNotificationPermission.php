<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddNotificationPermission extends Migration
{
    private const KEY = 'notificaciones.ver';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        if (! $this->db->table('permisos')->where('clave', self::KEY)->countAllResults()) {
            $this->db->table('permisos')->insert([
                'clave' => self::KEY,
                'descripcion' => 'Ver notificaciones y configurar preferencias propias',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permission = $this->db->table('permisos')->select('id')->where('clave', self::KEY)->get()->getRowArray();
        if ($permission === null) {
            return;
        }

        $roles = $this->db->table('roles')->select('id')->get()->getResultArray();
        foreach ($roles as $role) {
            $relation = ['rol_id' => (int) $role['id'], 'permiso_id' => (int) $permission['id']];
            if (! $this->db->table('rol_permisos')->where($relation)->countAllResults()) {
                $this->db->table('rol_permisos')->insert($relation + ['created_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        $permission = $this->db->table('permisos')->select('id')->where('clave', self::KEY)->get()->getRowArray();
        if ($permission === null) {
            return;
        }

        $permissionId = (int) $permission['id'];
        $this->db->table('rol_permisos')->where('permiso_id', $permissionId)->delete();
        $this->db->table('permisos')->where('id', $permissionId)->delete();
    }
}
