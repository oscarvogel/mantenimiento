<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateExpirationRegularizations extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('vencimientos')) {
            return;
        }

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'vencimiento_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'empleado_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'fecha_regularizacion' => ['type' => 'DATE'],
            'nueva_fecha_vencimiento' => ['type' => 'DATE'],
            'observaciones' => ['type' => 'TEXT', 'null' => true],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'PENDIENTE'],
            'revisado_por' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'revisado_at' => ['type' => 'DATETIME', 'null' => true],
            'motivo_rechazo' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['empresa_id', 'vencimiento_id', 'estado'], false, false, 'idx_exp_reg_pending_lookup');
        $this->forge->addKey(['empresa_id', 'empleado_id'], false, false, 'idx_exp_reg_employee');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('vencimiento_id', 'vencimientos', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('empleado_id', 'empleados', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('revisado_por', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('vencimiento_regularizaciones', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'regularizacion_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'ruta_archivo' => ['type' => 'VARCHAR', 'constraint' => 500],
            'nombre_original' => ['type' => 'VARCHAR', 'constraint' => 255],
            'mime_type' => ['type' => 'VARCHAR', 'constraint' => 100],
            'tamano_bytes' => ['type' => 'BIGINT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['empresa_id', 'regularizacion_id'], false, false, 'idx_exp_reg_attachment_scope');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('regularizacion_id', 'vencimiento_regularizaciones', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('vencimiento_regularizacion_adjuntos', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('vencimiento_regularizacion_adjuntos', true);
        $this->forge->dropTable('vencimiento_regularizaciones', true);
    }
}
