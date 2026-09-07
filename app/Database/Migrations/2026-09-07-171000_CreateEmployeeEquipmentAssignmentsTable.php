<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateEmployeeEquipmentAssignmentsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'empleado_id' => ['type' => 'INT', 'unsigned' => true],
            'equipo_id' => ['type' => 'INT', 'unsigned' => true],
            'rol' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'OTRO'],
            'fecha_desde' => ['type' => 'DATE'],
            'fecha_hasta' => ['type' => 'DATE', 'null' => true],
            'observaciones' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['empresa_id', 'empleado_id', 'fecha_hasta'], false, false, 'idx_assignment_employee_current');
        $this->forge->addKey(['empresa_id', 'equipo_id', 'rol', 'fecha_hasta'], false, false, 'idx_assignment_equipment_role_current');
        $this->forge->addKey(['empresa_id', 'fecha_desde']);
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('empleado_id', 'empleados', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('equipo_id', 'equipos', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('employee_equipment_assignments', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('employee_equipment_assignments', true);
    }
}
