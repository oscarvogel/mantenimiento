<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;

final class HardenChatbotTablesAndAddPermission extends Migration
{
    private const PERMISSION_KEY = 'chatbot.usar';

    public function up(): void
    {
        $this->hardenMensajes($this->db);
        $this->addChatbotPermission($this->db);
    }

    public function down(): void
    {
        $this->hardenMensajesDown($this->db);
        $this->removeChatbotPermission($this->db);
    }

    private function hardenMensajes(BaseConnection $db): void
    {
        if (! $db->tableExists('mensajes') || ! $db->tableExists('conversaciones')) {
            return;
        }

        $driver = $db->getDriver();
        if ($driver !== 'MySQLi') {
            return;
        }

        try {
            $indexes = $db->getIndexData('mensajes');
            $indexNames = is_array($indexes) ? array_column($indexes, 'name') : [];
        } catch (\Throwable) {
            $indexNames = [];
        }

        if (! in_array('idx_mensajes_tool_call_id', $indexNames, true)) {
            $db->query('CREATE INDEX idx_mensajes_tool_call_id ON mensajes (tool_call_id)');
        }

        try {
            $foreignKeys = $db->getForeignKeyData('mensajes');
            $foreignNames = is_array($foreignKeys) ? array_column($foreignKeys, 'constraint_name') : [];
        } catch (\Throwable) {
            $foreignNames = [];
        }

        if (! in_array('fk_mensajes_conversacion', $foreignNames, true)) {
            $db->query(
                'ALTER TABLE mensajes '
                . 'ADD CONSTRAINT fk_mensajes_conversacion '
                . 'FOREIGN KEY (conversacion_id) REFERENCES conversaciones (id) '
                . 'ON DELETE CASCADE ON UPDATE RESTRICT'
            );
        }
    }

    private function hardenMensajesDown(BaseConnection $db): void
    {
        if ($db->getDriver() !== 'MySQLi') {
            return;
        }
        try {
            $db->table('mensajes')
                ->dropForeignKey('conversacion_id', 'fk_mensajes_conversacion')
                ->dropForeignKey('tool_call_id', 'fk_mensajes_tool_call');
        } catch (\Throwable) {
        }
    }

    private function addChatbotPermission(BaseConnection $db): void
    {
        if (! $db->tableExists('permisos') || ! $db->tableExists('roles') || ! $db->tableExists('rol_permisos')) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        if (! $db->table('permisos')->where('clave', self::PERMISSION_KEY)->countAllResults()) {
            $db->table('permisos')->insert([
                'clave' => self::PERMISSION_KEY,
                'descripcion' => 'Usar el chatbot asistente del sistema de mantenimiento',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permission = $db->table('permisos')->select('id')->where('clave', self::PERMISSION_KEY)->get()->getRowArray();
        if ($permission === null) {
            return;
        }
        $permissionId = (int) $permission['id'];

        $roles = ['Administrador', 'Responsable de mantenimiento', 'Tecnico u operador', 'Solicitante'];
        foreach ($roles as $roleName) {
            $roleRow = $db->table('roles')->select('id')->where('nombre', $roleName)->get()->getRowArray();
            if ($roleRow === null) {
                continue;
            }

            $relation = ['rol_id' => (int) $roleRow['id'], 'permiso_id' => $permissionId];
            if (! $db->table('rol_permisos')->where($relation)->countAllResults()) {
                $db->table('rol_permisos')->insert($relation + ['created_at' => $now]);
            }
        }
    }

    private function removeChatbotPermission(BaseConnection $db): void
    {
        if (! $db->tableExists('permisos') || ! $db->tableExists('rol_permisos')) {
            return;
        }

        $permission = $db->table('permisos')->select('id')->where('clave', self::PERMISSION_KEY)->get()->getRowArray();
        if ($permission === null) {
            return;
        }
        $permissionId = (int) $permission['id'];

        $db->table('rol_permisos')->where('permiso_id', $permissionId)->delete();
        $db->table('permisos')->where('id', $permissionId)->delete();
    }
}
