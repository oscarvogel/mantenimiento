<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateExpirationTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 100],
            'aplica_a' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'EQUIPO'],
            'descripcion' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'dias_aviso_previo' => ['type' => 'INT', 'unsigned' => true, 'default' => 30],
            'requiere_documento' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['empresa_id', 'nombre'], 'uq_expiration_type_company_name');
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_expiration_type_company_id');
        $this->forge->addKey(['empresa_id', 'activo'], false, false, 'idx_expiration_type_scope');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('tipos_vencimiento', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'tipo_vencimiento_id' => ['type' => 'INT', 'unsigned' => true],
            'sujeto_tipo' => ['type' => 'VARCHAR', 'constraint' => 20],
            'equipo_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'empleado_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'fecha_emision' => ['type' => 'DATE', 'null' => true],
            'fecha_vencimiento' => ['type' => 'DATE'],
            'numero_documento' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'observaciones' => ['type' => 'TEXT', 'null' => true],
            'origen' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'MANUAL'],
            'importacion_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['empresa_id', 'equipo_id', 'tipo_vencimiento_id', 'fecha_vencimiento'], 'uq_expiration_equipment_type_date');
        $this->forge->addUniqueKey(['empresa_id', 'empleado_id', 'tipo_vencimiento_id', 'fecha_vencimiento'], 'uq_expiration_employee_type_date');
        $this->forge->addKey(['empresa_id', 'fecha_vencimiento', 'activo'], false, false, 'idx_expiration_company_date');
        $this->forge->addKey(['empresa_id', 'equipo_id'], false, false, 'idx_expiration_equipment');
        $this->forge->addKey(['empresa_id', 'empleado_id'], false, false, 'idx_expiration_employee');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('tipo_vencimiento_id', 'tipos_vencimiento', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('equipo_id', 'equipos', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('empleado_id', 'empleados', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('importacion_id', 'importaciones', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('vencimientos', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('vencimientos', true);
        $this->forge->dropTable('tipos_vencimiento', true);
    }
}
