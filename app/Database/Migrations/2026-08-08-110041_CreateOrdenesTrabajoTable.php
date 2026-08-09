<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateOrdenesTrabajoTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'numero' => ['type' => 'VARCHAR', 'constraint' => 20],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'equipo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'origen' => ['type' => 'VARCHAR', 'constraint' => 30],
            'plan_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'aviso_plan_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'tipo_servicio_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'prioridad' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'MEDIA'],
            'responsable_usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'fecha_apertura' => ['type' => 'DATETIME'],
            'fecha_objetivo' => ['type' => 'DATETIME', 'null' => true],
            'fecha_programada' => ['type' => 'DATETIME', 'null' => true],
            'fecha_inicio' => ['type' => 'DATETIME', 'null' => true],
            'fecha_finalizacion' => ['type' => 'DATETIME', 'null' => true],
            'km_ingreso' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'horas_ingreso' => ['type' => 'DECIMAL', 'constraint' => '12,1', 'unsigned' => true, 'null' => true],
            'km_salida' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'horas_salida' => ['type' => 'DECIMAL', 'constraint' => '12,1', 'unsigned' => true, 'null' => true],
            'diagnostico' => ['type' => 'TEXT', 'null' => true],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 30],
            'motivo_espera' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'motivo_cancelacion' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'equipo_fuera_servicio' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'inicio_detencion' => ['type' => 'DATETIME', 'null' => true],
            'fin_detencion' => ['type' => 'DATETIME', 'null' => true],
            'costo_mano_obra' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
            'costo_repuestos' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
            'otros_costos' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
            'costo_total' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'default' => 0],
            'observaciones' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['empresa_id', 'numero'], 'uq_ot_empresa_numero');
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_ot_empresa_id');
        $this->forge->addUniqueKey('aviso_plan_id', 'uq_ot_aviso_plan');
        $this->forge->addKey(['empresa_id', 'sucursal_id', 'estado'], false, false, 'idx_ot_scope_estado');
        $this->forge->addKey(['empresa_id', 'responsable_usuario_id', 'estado'], false, false, 'idx_ot_responsable_estado');
        $this->forge->addKey(['empresa_id', 'equipo_id', 'fecha_apertura'], false, false, 'idx_ot_equipo_fecha');
        $this->forge->addKey(['empresa_id', 'plan_id', 'estado'], false, false, 'idx_ot_plan_estado');

        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_ot_empresa');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT', 'fk_ot_sucursal');
        $this->forge->addForeignKey(['empresa_id', 'equipo_id'], 'equipos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_ot_equipo_tenant');
        $this->forge->addForeignKey(['empresa_id', 'plan_id'], 'planes_mantenimiento', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_ot_plan_tenant');
        $this->forge->addForeignKey(['empresa_id', 'aviso_plan_id'], 'avisos_plan', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_ot_aviso_tenant');
        $this->forge->addForeignKey('tipo_servicio_id', 'tipos_servicio', 'id', 'RESTRICT', 'RESTRICT', 'fk_ot_tipo_servicio');
        $this->forge->addForeignKey('responsable_usuario_id', 'usuarios', 'id', 'RESTRICT', 'RESTRICT', 'fk_ot_responsable');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'RESTRICT', 'RESTRICT', 'fk_ot_created_by');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'RESTRICT', 'RESTRICT', 'fk_ot_updated_by');
        $this->forge->createTable('ordenes_trabajo');
    }

    public function down(): void
    {
        $this->forge->dropTable('ordenes_trabajo');
    }
}
