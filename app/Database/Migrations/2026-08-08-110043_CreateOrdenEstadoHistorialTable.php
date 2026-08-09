<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateOrdenEstadoHistorialTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'orden_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'estado_anterior' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'estado_nuevo' => ['type' => 'VARCHAR', 'constraint' => 30],
            'fecha' => ['type' => 'DATETIME'],
            'usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'comentario' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['empresa_id', 'orden_id', 'fecha'], false, false, 'idx_ot_historial_scope_fecha');
        $this->forge->addForeignKey(['empresa_id', 'orden_id'], 'ordenes_trabajo', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_ot_historial_tenant');
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'RESTRICT', 'RESTRICT', 'fk_ot_historial_usuario');
        $this->forge->createTable('orden_estado_historial');
    }

    public function down(): void
    {
        $this->forge->dropTable('orden_estado_historial');
    }
}
