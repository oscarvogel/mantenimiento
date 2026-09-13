<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class BackfillChatbotPermissionRoleDefaults extends Migration
{
    private const PERMISSION_KEY = 'chatbot.usar';

    public function up(): void
    {
        if (! $this->db->tableExists('permisos') || ! $this->db->tableExists('roles') || ! $this->db->tableExists('rol_permisos')) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $permission = $this->db->table('permisos')
            ->select('id')
            ->where('clave', self::PERMISSION_KEY)
            ->get()
            ->getRowArray();

        if ($permission === null) {
            $this->db->table('permisos')->insert([
                'clave' => self::PERMISSION_KEY,
                'descripcion' => 'Usar el chatbot asistente del sistema de mantenimiento',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $permissionId = (int) $this->db->insertID();
        } else {
            $permissionId = (int) $permission['id'];
        }

        foreach (['Administrador', 'Responsable de mantenimiento', 'Tecnico u operador', 'Solicitante'] as $roleName) {
            $role = $this->db->table('roles')
                ->select('id')
                ->where('nombre', $roleName)
                ->get()
                ->getRowArray();

            if ($role === null) {
                continue;
            }

            $relation = [
                'rol_id' => (int) $role['id'],
                'permiso_id' => $permissionId,
            ];

            if (! $this->db->table('rol_permisos')->where($relation)->countAllResults()) {
                $this->db->table('rol_permisos')->insert($relation + ['created_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // Backfill correctivo: no se revocan permisos existentes al revertir.
    }
}
