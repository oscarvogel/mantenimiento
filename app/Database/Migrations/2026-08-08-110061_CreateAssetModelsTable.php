<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateAssetModelsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'marca_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tipo_equipo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 100],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['empresa_id', 'marca_id', 'tipo_equipo_id', 'nombre'], 'uq_modelos_scope_nombre');
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_modelos_empresa_id');
        $this->forge->addKey(['empresa_id', 'activo'], false, false, 'idx_modelos_scope');
        $this->forge->addKey('tipo_equipo_id');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_modelos_empresa');
        $this->forge->addForeignKey(['empresa_id', 'marca_id'], 'marcas', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_modelos_marca_tenant');
        $this->forge->addForeignKey('tipo_equipo_id', 'tipos_equipo', 'id', 'RESTRICT', 'RESTRICT', 'fk_modelos_tipo');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'RESTRICT', 'SET NULL', 'fk_modelos_created_by');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'RESTRICT', 'SET NULL', 'fk_modelos_updated_by');
        $this->forge->createTable('modelos');
    }

    public function down(): void
    {
        $this->forge->dropTable('modelos');
    }
}
