<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateEquipmentTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tipo_equipo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'codigo' => ['type' => 'VARCHAR', 'constraint' => 50],
            'patente' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'km_actual' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'horas_actuales' => [
                'type'       => 'DECIMAL',
                'constraint' => '12,1',
                'unsigned'   => true,
                'null'       => true,
            ],
            'estado' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'ACTIVO',
            ],
            'fecha_alta' => ['type' => 'DATE'],
            'fecha_baja' => ['type' => 'DATE', 'null' => true],
            'observaciones' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['empresa_id', 'codigo']);
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_equipos_empresa_id');
        $this->forge->addKey(['empresa_id', 'sucursal_id', 'estado'], false, false, 'idx_equipos_scope');
        $this->forge->addKey('tipo_equipo_id');
        $this->forge->addKey(['empresa_id', 'patente']);
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('tipo_equipo_id', 'tipos_equipo', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'RESTRICT', 'SET NULL');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'RESTRICT', 'SET NULL');
        $this->forge->createTable('equipos');
    }

    public function down(): void
    {
        $this->forge->dropTable('equipos');
    }
}
