<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddTripDashboardPermission extends Migration
{
    private const PERMISSIONS = [
        'viajes.ver' => 'Ver el dashboard de viajes de la empresa',
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
        $row = $this->db->table('permisos')->select('id')->where('clave', 'viajes.ver')->get()->getRowArray();
        $permissionId = (int) ($row['id'] ?? 0);
        if ($permissionId === 0) {
            return;
        }

        $roleIds = [];
        foreach ($this->db->table('roles')->select('id')
            ->whereIn('nombre', ['Administrador', 'Responsable de mantenimiento', 'Consulta'])
            ->get()->getResultArray() as $role) {
            $roleIds[] = (int) $role['id'];
        }
        // Quien ya consulta los maestros de Viajes tambien puede ver el dashboard.
        $rows = $this->db->query(
            'SELECT DISTINCT rp.rol_id FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id WHERE p.clave = ?',
            ['viajes.maestros.ver'],
        )->getResultArray();
        foreach ($rows as $row) {
            $roleIds[] = (int) $row['rol_id'];
        }

        foreach (array_unique($roleIds) as $roleId) {
            $relation = ['rol_id' => $roleId, 'permiso_id' => $permissionId];
            if (! $this->db->table('rol_permisos')->where($relation)->countAllResults()) {
                $this->db->table('rol_permisos')->insert($relation + ['created_at' => $now]);
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
