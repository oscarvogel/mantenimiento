<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Permiso del tablero de flota en Gerencial: conciliación de combustible, uso
 * de la flota y services estimados por GPS. Los tres casos de uso ya lo exigen.
 * Se asigna a Administrador y Gerencial.
 */
final class AddFleetBoardPermission extends Migration
{
    private const PERMISSION = 'gerencial.flota.ver';
    private const DESCRIPTION = 'Consultar el tablero de flota dentro de Gerencial';

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        if (! $this->db->table('permisos')->where('clave', self::PERMISSION)->countAllResults()) {
            $this->db->table('permisos')->insert([
                'clave' => self::PERMISSION,
                'descripcion' => self::DESCRIPTION,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permission = $this->db->table('permisos')->select('id')->where('clave', self::PERMISSION)->get()->getRowArray();
        if ($permission === null) {
            return;
        }

        $roles = $this->db->table('roles')->select('id')
            ->whereIn('nombre', ['Administrador', 'Gerencial'])->get()->getResultArray();
        foreach ($roles as $role) {
            $relation = ['rol_id' => (int) $role['id'], 'permiso_id' => (int) $permission['id']];
            if (! $this->db->table('rol_permisos')->where($relation)->countAllResults()) {
                $this->db->table('rol_permisos')->insert($relation + ['created_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        $permission = $this->db->table('permisos')->select('id')->where('clave', self::PERMISSION)->get()->getRowArray();
        if ($permission === null) {
            return;
        }

        $this->db->table('rol_permisos')->where('permiso_id', (int) $permission['id'])->delete();
        $this->db->table('permisos')->where('id', (int) $permission['id'])->delete();
    }
}
