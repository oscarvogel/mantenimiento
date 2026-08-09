<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateOrdenTareasTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'orden_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tarea_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'descripcion_solicitada' => ['type' => 'TEXT'],
            'obligatoria' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'orden' => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true],
            'trabajo_realizado' => ['type' => 'TEXT', 'null' => true],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'PENDIENTE'],
            'responsable_usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'fecha_inicio' => ['type' => 'DATETIME', 'null' => true],
            'fecha_fin' => ['type' => 'DATETIME', 'null' => true],
            'observaciones' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['orden_id', 'orden'], 'uq_orden_tarea_posicion');
        $this->forge->addKey(['empresa_id', 'orden_id', 'estado'], false, false, 'idx_orden_tarea_scope_estado');
        $this->forge->addForeignKey(['empresa_id', 'orden_id'], 'ordenes_trabajo', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_orden_tarea_ot_tenant');
        $this->forge->addForeignKey('tarea_id', 'tareas_mantenimiento', 'id', 'RESTRICT', 'RESTRICT', 'fk_orden_tarea_catalogo');
        $this->forge->addForeignKey('responsable_usuario_id', 'usuarios', 'id', 'RESTRICT', 'RESTRICT', 'fk_orden_tarea_responsable');
        $this->forge->createTable('orden_tareas');
    }

    public function down(): void
    {
        $this->forge->dropTable('orden_tareas');
    }
}
