<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateNotificationDeliveries extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'notificacion_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'canal' => ['type' => 'VARCHAR', 'constraint' => 16],
            'clave_entrega' => ['type' => 'VARCHAR', 'constraint' => 220],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'PENDIENTE'],
            'intentos' => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'proximo_intento' => ['type' => 'DATETIME', 'null' => true],
            'enviada_en' => ['type' => 'DATETIME', 'null' => true],
            'ultimo_error' => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('clave_entrega');
        $this->forge->addKey(['canal', 'estado', 'proximo_intento']);
        $this->forge->addForeignKey('notificacion_id', 'notificaciones', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('notificacion_entregas');

        $this->forge->reset();
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'proceso' => ['type' => 'VARCHAR', 'constraint' => 100],
            'clave_ejecucion' => ['type' => 'VARCHAR', 'constraint' => 160],
            'fecha_inicio' => ['type' => 'DATETIME'],
            'fecha_fin' => ['type' => 'DATETIME', 'null' => true],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20],
            'resumen' => ['type' => 'TEXT', 'null' => true],
            'detalle_error' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['proceso', 'clave_ejecucion']);
        $this->forge->addKey(['proceso', 'fecha_inicio']);
        $this->forge->createTable('ejecuciones_programadas');

        $this->forge->reset();
        $this->forge->addField([
            'proceso' => ['type' => 'VARCHAR', 'constraint' => 100],
            'token' => ['type' => 'CHAR', 'constraint' => 64],
            'adquirido_en' => ['type' => 'DATETIME'],
            'expira_en' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('proceso');
        $this->forge->createTable('bloqueos_proceso');
    }

    public function down(): void
    {
        $this->forge->dropTable('bloqueos_proceso');
        $this->forge->dropTable('ejecuciones_programadas');
        $this->forge->dropTable('notificacion_entregas');
    }
}
