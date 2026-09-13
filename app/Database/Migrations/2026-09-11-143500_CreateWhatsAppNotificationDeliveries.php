<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateWhatsAppNotificationDeliveries extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'equipo_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'empleado_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'tipo_evento' => ['type' => 'VARCHAR', 'constraint' => 80],
            'clave_entrega' => ['type' => 'VARCHAR', 'constraint' => 220],
            'external_ref' => ['type' => 'VARCHAR', 'constraint' => 220],
            'telefono' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'mensaje' => ['type' => 'TEXT'],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'PENDIENTE'],
            'gateway_message_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'gateway_status' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'intentos' => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'proximo_intento' => ['type' => 'DATETIME', 'null' => true],
            'enviada_en' => ['type' => 'DATETIME', 'null' => true],
            'ultimo_error' => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('clave_entrega');
        $this->forge->addKey(['estado', 'proximo_intento']);
        $this->forge->addKey(['empresa_id', 'equipo_id']);
        $this->forge->addKey(['empresa_id', 'empleado_id']);
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('equipo_id', 'equipos', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('empleado_id', 'empleados', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('notificacion_whatsapp_entregas', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('notificacion_whatsapp_entregas', true);
    }
}
