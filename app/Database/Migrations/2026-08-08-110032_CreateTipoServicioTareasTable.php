<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateTipoServicioTareasTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'tipo_servicio_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tarea_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'orden'            => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'default' => 1],
            'obligatoria'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'observaciones'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey(['tipo_servicio_id', 'tarea_id']);
        $this->forge->addUniqueKey(['tipo_servicio_id', 'orden']);
        $this->forge->addKey('tarea_id');
        $this->forge->addForeignKey('tipo_servicio_id', 'tipos_servicio', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('tarea_id', 'tareas_mantenimiento', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('tipo_servicio_tareas');
    }

    public function down(): void
    {
        $this->forge->dropTable('tipo_servicio_tareas');
    }
}
