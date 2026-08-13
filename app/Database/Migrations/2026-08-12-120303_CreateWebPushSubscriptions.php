<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateWebPushSubscriptions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'endpoint_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'endpoint' => ['type' => 'TEXT'],
            'p256dh' => ['type' => 'VARCHAR', 'constraint' => 255],
            'auth' => ['type' => 'VARCHAR', 'constraint' => 255],
            'content_encoding' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'aes128gcm'],
            'user_agent' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'nombre_dispositivo' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'fecha_alta' => ['type' => 'DATETIME'],
            'ultimo_uso' => ['type' => 'DATETIME', 'null' => true],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'fecha_baja' => ['type' => 'DATETIME', 'null' => true],
            'ultimo_error' => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['usuario_id', 'endpoint_hash']);
        $this->forge->addKey(['usuario_id', 'activo']);
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('webpush_subscriptions');
    }

    public function down(): void
    {
        $this->forge->dropTable('webpush_subscriptions');
    }
}
