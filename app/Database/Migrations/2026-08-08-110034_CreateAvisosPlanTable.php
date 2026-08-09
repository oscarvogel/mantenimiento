<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateAvisosPlanTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'plan_id'                 => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'equipo_id'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'clave_ciclo'             => ['type' => 'CHAR', 'constraint' => 64],
            'estado_calculado'        => ['type' => 'VARCHAR', 'constraint' => 20],
            'criterios_disparadores'  => ['type' => 'VARCHAR', 'constraint' => 64],
            'detalle_evaluacion'      => ['type' => 'TEXT', 'null' => true],
            'fecha_deteccion'         => ['type' => 'DATETIME'],
            'estado_gestion'          => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'PENDIENTE'],
            'fecha_resolucion'        => ['type' => 'DATETIME', 'null' => true],
            'motivo_resolucion'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_by'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at'              => ['type' => 'DATETIME', 'null' => true],
            'updated_at'              => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['empresa_id', 'id']);
        $this->forge->addUniqueKey(['empresa_id', 'plan_id', 'clave_ciclo']);
        $this->forge->addKey(['empresa_id', 'estado_gestion', 'fecha_deteccion']);
        $this->forge->addKey(['empresa_id', 'equipo_id']);
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey(['empresa_id', 'plan_id'], 'planes_mantenimiento', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey(['empresa_id', 'equipo_id'], 'equipos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('avisos_plan');
    }

    public function down(): void
    {
        $this->forge->dropTable('avisos_plan');
    }
}
