<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddChatbotAuditPermissions extends Migration
{
    private const GLOBAL = 'chatbot.auditoria.global';
    private const COMPANY = 'chatbot.auditoria.empresa';

    public function up(): void
    {
        if (! $this->db->tableExists('permisos')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->ensurePermission(self::GLOBAL, 'Auditar conversaciones del chatbot de todas las empresas', $now);
        $companyPermissionId = $this->ensurePermission(
            self::COMPANY,
            'Auditar conversaciones del chatbot dentro de la propia empresa',
            $now,
        );

        if ($companyPermissionId === null || ! $this->db->tableExists('roles') || ! $this->db->tableExists('rol_permisos')) {
            return;
        }

        $administrator = $this->db->table('roles')->select('id')->where('nombre', 'Administrador')->get()->getRowArray();
        if ($administrator === null) {
            return;
        }

        $relation = [
            'rol_id' => (int) $administrator['id'],
            'permiso_id' => $companyPermissionId,
        ];
        if (! $this->db->table('rol_permisos')->where($relation)->countAllResults()) {
            $this->db->table('rol_permisos')->insert($relation + ['created_at' => $now]);
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('permisos')) {
            return;
        }

        foreach ([self::GLOBAL, self::COMPANY] as $key) {
            $row = $this->db->table('permisos')->select('id')->where('clave', $key)->get()->getRowArray();
            if ($row === null) {
                continue;
            }
            $permissionId = (int) $row['id'];
            if ($this->db->tableExists('rol_permisos')) {
                $this->db->table('rol_permisos')->where('permiso_id', $permissionId)->delete();
            }
            $this->db->table('permisos')->where('id', $permissionId)->delete();
        }
    }

    private function ensurePermission(string $key, string $description, string $now): ?int
    {
        $row = $this->db->table('permisos')->select('id')->where('clave', $key)->get()->getRowArray();
        if ($row !== null) {
            return (int) $row['id'];
        }

        $this->db->table('permisos')->insert([
            'clave' => $key,
            'descripcion' => $description,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->db->insertID();
        return $id > 0 ? $id : null;
    }
}
