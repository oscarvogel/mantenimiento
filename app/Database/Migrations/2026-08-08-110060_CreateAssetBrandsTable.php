<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateAssetBrandsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 100],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['empresa_id', 'nombre'], 'uq_marcas_empresa_nombre');
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_marcas_empresa_id');
        $this->forge->addKey(['empresa_id', 'activo'], false, false, 'idx_marcas_scope');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_marcas_empresa');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'RESTRICT', 'SET NULL', 'fk_marcas_created_by');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'RESTRICT', 'SET NULL', 'fk_marcas_updated_by');
        $this->forge->createTable('marcas');
    }

    public function down(): void
    {
        $this->forge->dropTable('marcas');
    }
}
