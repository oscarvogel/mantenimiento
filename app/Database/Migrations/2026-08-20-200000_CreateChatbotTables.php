<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateChatbotTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'usuario_id' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'titulo' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
            'updated_at' => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['empresa_id', 'usuario_id']);
        $this->forge->createTable('conversaciones');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'conversacion_id' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'role' => ['type' => 'ENUM', 'constraint' => ['user', 'assistant', 'system', 'tool'], 'null' => false],
            'content' => ['type' => 'TEXT', 'null' => false],
            'tool_calls' => ['type' => 'JSON', 'null' => true],
            'tool_call_id' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'tokens_used' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['conversacion_id', 'created_at']);
        $this->forge->createTable('mensajes');
    }

    public function down(): void
    {
        $this->forge->dropTable('mensajes');
        $this->forge->dropTable('conversaciones');
    }
}
