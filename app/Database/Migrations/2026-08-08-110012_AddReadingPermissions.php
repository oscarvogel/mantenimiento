<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddReadingPermissions extends Migration
{
    private const PERMISSIONS = [
        'lecturas.ver' => 'Ver historial de lecturas',
        'lecturas.corregir' => 'Corregir lecturas con motivo y trazabilidad',
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        foreach (self::PERMISSIONS as $key => $description) {
            if (! $this->db->table('permisos')->where('clave', $key)->countAllResults()) {
                $this->db->table('permisos')->insert([
                    'clave' => $key, 'descripcion' => $description,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $permissionRows = $this->db->table('permisos')->select('id, clave')
            ->whereIn('clave', array_keys(self::PERMISSIONS))->get()->getResultArray();
        $permissionIds = array_column($permissionRows, 'id', 'clave');
        $roleRows = $this->db->table('roles')->select('id, nombre')
            ->whereIn('nombre', ['Administrador', 'Responsable de mantenimiento', 'Tecnico u operador'])
            ->get()->getResultArray();

        foreach ($roleRows as $role) {
            $keys = $role['nombre'] === 'Tecnico u operador'
                ? ['lecturas.ver']
                : ['lecturas.ver', 'lecturas.corregir'];
            foreach ($keys as $key) {
                $relation = ['rol_id' => (int) $role['id'], 'permiso_id' => (int) $permissionIds[$key]];
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
        if ($ids !== []) {
            $this->db->table('rol_permisos')->whereIn('permiso_id', $ids)->delete();
            $this->db->table('permisos')->whereIn('id', $ids)->delete();
        }
    }
}
