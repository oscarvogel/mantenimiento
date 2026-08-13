<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateNotificationPreferences extends Migration
{
    public function up(): void
    {
        $fields = [
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tipo_evento' => ['type' => 'VARCHAR', 'constraint' => 80],
            'modo_interno' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'INMEDIATO'],
            'modo_email' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'RESUMEN'],
            'modo_push' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'CRITICO'],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ];

        $this->forge->addField(['usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true]] + $fields);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['usuario_id', 'tipo_evento']);
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('preferencias_notificacion');

        $this->forge->reset();
        $this->forge->addField(['rol_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true]] + $fields);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['rol_id', 'tipo_evento']);
        $this->forge->addForeignKey('rol_id', 'roles', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('preferencias_notificacion_rol');

        $events = ['preventivo.vencido', 'preventivo.proximo', 'orden.asignada', 'orden.demorada', 'solicitud.critica', 'equipo.sin_lectura', 'garantia.proxima'];
        $roles = $this->db->table('roles')->select('id, nombre')->get()->getResultArray();
        $now = date('Y-m-d H:i:s');
        foreach ($roles as $role) {
            foreach ($events as $event) {
                $email = (string) $role['nombre'] === 'Consulta' ? 'DESACTIVADO' : 'RESUMEN';
                $push = (string) $role['nombre'] === 'Consulta' ? 'DESACTIVADO' : 'CRITICO';
                if ((string) $role['nombre'] === 'Tecnico u operador' && $event === 'orden.asignada') {
                    $email = 'DESACTIVADO';
                    $push = 'INMEDIATO';
                }
                $this->db->table('preferencias_notificacion_rol')->ignore(true)->insert([
                    'rol_id' => (int) $role['id'], 'tipo_evento' => $event,
                    'modo_interno' => 'INMEDIATO', 'modo_email' => $email, 'modo_push' => $push,
                    'created_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('preferencias_notificacion_rol');
        $this->forge->dropTable('preferencias_notificacion');
    }
}
