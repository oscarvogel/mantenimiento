<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Permiso propio para consultar e imprimir la liquidación del chofer (#533).
 *
 * Hasta ahora el PDF de liquidación colgaba de `viajes.ver`. Se separa para que
 * la empresa decida quién revisa liquidaciones con los choferes. Lo reciben los
 * roles que ya cierran viajes (`viajes.operar`) o ven el económico
 * (`viajes.economico.ver`): Administrador, Responsable de mantenimiento y
 * Gerencial en la instalación estándar. Ningún rol pierde acceso a la vista
 * administrativa del viaje.
 */
final class AddDriverSettlementViewPermission extends Migration
{
    private const PERMISSION = 'viajes.liquidacion_chofer.ver';

    private const GRANTED_WHEN_ROLE_HAS = ['viajes.operar', 'viajes.economico.ver'];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        if (! $this->db->table('permisos')->where('clave', self::PERMISSION)->countAllResults()) {
            $this->db->table('permisos')->insert([
                'clave' => self::PERMISSION,
                'descripcion' => 'Consultar e imprimir la liquidación del chofer de un viaje',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $permission = $this->db->table('permisos')->select('id')->where('clave', self::PERMISSION)->get()->getRowArray();
        $permissionId = (int) ($permission['id'] ?? 0);
        if ($permissionId === 0) {
            return;
        }

        $roleIds = [];
        foreach ($this->db->table('roles')->select('id')->where('nombre', 'Administrador')->get()->getResultArray() as $role) {
            $roleIds[(int) $role['id']] = true;
        }
        $sourceIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->db->table('permisos')->select('id')->whereIn('clave', self::GRANTED_WHEN_ROLE_HAS)->get()->getResultArray(),
        );
        if ($sourceIds !== []) {
            foreach ($this->db->table('rol_permisos')->select('rol_id')->whereIn('permiso_id', $sourceIds)->get()->getResultArray() as $row) {
                $roleIds[(int) $row['rol_id']] = true;
            }
        }

        foreach (array_keys($roleIds) as $roleId) {
            $relation = ['rol_id' => $roleId, 'permiso_id' => $permissionId];
            if (! $this->db->table('rol_permisos')->where($relation)->countAllResults()) {
                $this->db->table('rol_permisos')->insert($relation + ['created_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->db->table('permisos')->select('id')->where('clave', self::PERMISSION)->get()->getResultArray(),
        );
        if ($ids === []) {
            return;
        }

        $this->db->table('rol_permisos')->whereIn('permiso_id', $ids)->delete();
        $this->db->table('permisos')->whereIn('id', $ids)->delete();
    }
}
