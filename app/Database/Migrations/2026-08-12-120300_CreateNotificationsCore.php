<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateNotificationsCore extends Migration
{
    public function up(): void
    {
        // Una FK compuesta evita asociar una notificación a un usuario de otra empresa.
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_usuarios_empresa_id_id');
        $this->forge->processIndexes('usuarios');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tipo_evento' => ['type' => 'VARCHAR', 'constraint' => 80],
            'severidad' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'INFO'],
            'titulo' => ['type' => 'VARCHAR', 'constraint' => 180],
            'resumen' => ['type' => 'VARCHAR', 'constraint' => 500],
            'entidad_tipo' => ['type' => 'VARCHAR', 'constraint' => 80],
            'entidad_id' => ['type' => 'VARCHAR', 'constraint' => 80],
            'url' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'clave_evento' => ['type' => 'VARCHAR', 'constraint' => 190],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'PENDIENTE'],
            'leida_en' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['usuario_id', 'clave_evento']);
        $this->forge->addKey(['empresa_id', 'usuario_id', 'leida_en', 'created_at']);
        $this->forge->addKey(['empresa_id', 'sucursal_id', 'tipo_evento']);
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey(['empresa_id', 'sucursal_id'], 'sucursales', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey(['empresa_id', 'usuario_id'], 'usuarios', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('notificaciones');
    }

    public function down(): void
    {
        $this->forge->dropTable('notificaciones');
        $this->forge->dropKey('usuarios', 'uq_usuarios_empresa_id_id');
    }
}
