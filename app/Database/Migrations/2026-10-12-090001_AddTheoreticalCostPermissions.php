<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddTheoreticalCostPermissions extends Migration
{
    private const PERMISSIONS = [
        'gerencial.costo_km.ver' => 'Consultar costo teórico por kilómetro dentro de Gerencial',
        'gerencial.costo_km.editar' => 'Configurar y guardar escenarios de costo teórico por kilómetro',
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        foreach (self::PERMISSIONS as $key => $description) {
            if (! $this->db->table('permisos')->where('clave', $key)->countAllResults()) {
                $this->db->table('permisos')->insert([
                    'clave' => $key,
                    'descripcion' => $description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (! $this->db->table('roles')->where('nombre', 'Gerencial')->countAllResults()) {
            $this->db->table('roles')->insert([
                'nombre' => 'Gerencial',
                'descripcion' => 'Consultar y configurar costos teóricos por kilómetro y análisis gerencial.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionIds = $this->db->table('permisos')->select('id, clave')
            ->whereIn('clave', array_keys(self::PERMISSIONS))->get()->getResultArray();
        $permissionIds = array_column($permissionIds, 'id', 'clave');
        $roleRows = $this->db->table('roles')->select('id, nombre')
            ->whereIn('nombre', ['Administrador', 'Gerencial'])->get()->getResultArray();
        foreach ($roleRows as $role) {
            $keys = array_keys(self::PERMISSIONS);
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
        $permissions = $this->db->table('permisos')->select('id')->whereIn('clave', array_keys(self::PERMISSIONS))->get()->getResultArray();
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $permissions);
        if ($ids !== []) {
            $this->db->table('rol_permisos')->whereIn('permiso_id', $ids)->delete();
            $this->db->table('permisos')->whereIn('id', $ids)->delete();
        }
        // The role is retained because tenant users may already have been assigned to it.
    }
}
