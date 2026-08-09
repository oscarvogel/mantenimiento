<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateTiposServicioTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'codigo'      => ['type' => 'VARCHAR', 'constraint' => 50],
            'nombre'      => ['type' => 'VARCHAR', 'constraint' => 150],
            'descripcion' => ['type' => 'TEXT', 'null' => true],
            'activo'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('codigo');
        $this->forge->addKey(['activo', 'nombre']);
        $this->forge->createTable('tipos_servicio');
    }

    public function down(): void
    {
        $this->forge->dropTable('tipos_servicio');
    }
}
