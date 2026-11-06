<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Lectura del centro de automatizaciones.
 *
 * Es un permiso distinto de `notificaciones.ver`: mirar el seguimiento de los
 * procesos no habilita a ejecutar ni despachar nada. Se asigna explícitamente
 * a Administrador y Responsable de mantenimiento.
 */
final class AddAutomationViewPermission extends Migration
{
    private const PERMISSION = 'automatizaciones.ver';

    private const ROLES = ['Administrador', 'Responsable de mantenimiento'];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        if (! $this->db->table('permisos')->where('clave', self::PERMISSION)->countAllResults()) {
            $this->db->table('permisos')->insert([
                'clave' => self::PERMISSION,
                'descripcion' => 'Consultar el seguimiento de automatizaciones y sus entregas',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permission = $this->db->table('permisos')
            ->select('id')
            ->where('clave', self::PERMISSION)
            ->get()
            ->getRowArray();
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
    }

    public function down(): void
    {
        $rows = $this->db->table('permisos')
            ->select('id')
            ->where('clave', self::PERMISSION)
            ->get()
            ->getResultArray();
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        if ($ids === []) {
            return;
        }

        $this->db->table('rol_permisos')->whereIn('permiso_id', $ids)->delete();
        $this->db->table('permisos')->whereIn('id', $ids)->delete();
    }
}
