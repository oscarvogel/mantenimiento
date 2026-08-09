<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateOrdenNumeradoresTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'anio' => ['type' => 'SMALLINT', 'constraint' => 4, 'unsigned' => true],
            'ultimo_numero' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey(['empresa_id', 'anio']);
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_orden_numerador_empresa');
        $this->forge->createTable('orden_numeradores');
    }

    public function down(): void
    {
        $this->forge->dropTable('orden_numeradores');
    }
}
