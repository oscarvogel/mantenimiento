<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Catálogo y registros de vencimientos del bounded context de mantenimiento.
 *
 * La primera integración importa vencimientos de equipos. El modelo conserva
 * el sujeto y el alcance de empresa/sucursal para poder incorporar empleados
 * sin cambiar el contrato de importación en una etapa posterior.
 */
final class CreateExpirationTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 100],
            'aplica_a' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'EQUIPO'],
            'descripcion' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'dias_aviso_previo' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'default' => 30],
            'requiere_documento' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['empresa_id', 'nombre'], 'uq_tipos_vencimiento_empresa_nombre');
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_tipos_vencimiento_empresa_id');
        $this->forge->addKey(['empresa_id', 'activo'], false, false, 'idx_tipos_vencimiento_scope');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'RESTRICT', 'SET NULL');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'RESTRICT', 'SET NULL');
        $this->forge->createTable('tipos_vencimiento', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tipo_vencimiento_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sujeto_tipo' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'EQUIPO'],
            'equipo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'fecha_emision' => ['type' => 'DATE', 'null' => true],
            'fecha_vencimiento' => ['type' => 'DATE'],
            'numero_documento' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'observaciones' => ['type' => 'TEXT', 'null' => true],
            'origen' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'MANUAL'],
            'importacion_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(
            ['empresa_id', 'equipo_id', 'tipo_vencimiento_id', 'fecha_vencimiento'],
            'uq_vencimientos_equipo_tipo_fecha',
        );
        $this->forge->addKey(['empresa_id', 'sucursal_id', 'fecha_vencimiento', 'activo'], false, false, 'idx_vencimientos_scope_fecha');
        $this->forge->addKey(['empresa_id', 'equipo_id'], false, false, 'idx_vencimientos_equipo');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey(['empresa_id', 'sucursal_id'], 'sucursales', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_vencimientos_sucursal_tenant');
        $this->forge->addForeignKey(['empresa_id', 'tipo_vencimiento_id'], 'tipos_vencimiento', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_vencimientos_tipo_tenant');
        $this->forge->addForeignKey(['empresa_id', 'equipo_id'], 'equipos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_vencimientos_equipo_tenant');
        // empresa_id forma parte de la FK compuesta y no puede quedar NULL;
        // por eso se conserva la referencia histórica con RESTRICT.
        $this->forge->addForeignKey(['empresa_id', 'importacion_id'], 'importaciones', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_vencimientos_importacion_tenant');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'RESTRICT', 'SET NULL');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'RESTRICT', 'SET NULL');
        $this->forge->createTable('vencimientos', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('vencimientos', true);
        $this->forge->dropTable('tipos_vencimiento', true);
    }
}
