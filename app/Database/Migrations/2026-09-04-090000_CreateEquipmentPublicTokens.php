<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateEquipmentPublicTokens extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'equipo_id' => ['type' => 'INT', 'unsigned' => true],
            'token_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'revoked_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'revoked_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey(['empresa_id', 'equipo_id', 'activo']);
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('equipo_id', 'equipos', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('revoked_by', 'usuarios', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('equipo_tokens_publicos', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('equipo_tokens_publicos', true);
    }
}
