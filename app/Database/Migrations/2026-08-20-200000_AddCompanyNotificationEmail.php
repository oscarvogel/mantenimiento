<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddCompanyNotificationEmail extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('empresas', [
            'email_notificaciones' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'after' => 'email',
            ],
            'notificaciones_email_habilitadas' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'default' => 1,
                'after' => 'email_notificaciones',
            ],
        ]);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tipo_evento' => ['type' => 'VARCHAR', 'constraint' => 100],
            'destinatario' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'clave_entrega' => ['type' => 'VARCHAR', 'constraint' => 220],
            'titulo' => ['type' => 'VARCHAR', 'constraint' => 255],
            'resumen' => ['type' => 'TEXT'],
            'url' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
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
        $this->forge->addKey(['empresa_id', 'tipo_evento']);
        $this->forge->addKey(['estado', 'proximo_intento']);
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('notificacion_empresa_entregas');
    }

    public function down(): void
    {
        $this->forge->dropTable('notificacion_empresa_entregas');
        $this->forge->dropColumn('empresas', ['email_notificaciones', 'notificaciones_email_habilitadas']);
    }
}
