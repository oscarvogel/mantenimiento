<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateUsuarioAccesoHistorialTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'usuario_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'empresa_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'accion' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
            ],
            'detalle_anterior' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'detalle_nuevo' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'motivo' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'superadmin_usuario_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['usuario_id', 'created_at']);
        $this->forge->addKey(['empresa_id', 'created_at']);
        $this->forge->addKey('superadmin_usuario_id');
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('superadmin_usuario_id', 'usuarios', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('usuario_acceso_historial');
    }

    public function down(): void
    {
        $this->forge->dropTable('usuario_acceso_historial');
    }
}
