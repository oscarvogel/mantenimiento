<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreatePlanesMantenimientoTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'equipo_id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tipo_servicio_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'intervalo_km'         => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'intervalo_horas'      => ['type' => 'DECIMAL', 'constraint' => '12,1', 'unsigned' => true, 'null' => true],
            'intervalo_dias'       => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'anticipacion_km'      => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'anticipacion_horas'   => ['type' => 'DECIMAL', 'constraint' => '12,1', 'unsigned' => true, 'null' => true],
            'anticipacion_dias'    => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true],
            'base_km'              => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'base_horas'           => ['type' => 'DECIMAL', 'constraint' => '12,1', 'unsigned' => true, 'null' => true],
            'base_fecha'           => ['type' => 'DATE', 'null' => true],
            'proximo_km'           => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'proximas_horas'       => ['type' => 'DECIMAL', 'constraint' => '12,1', 'unsigned' => true, 'null' => true],
            'proxima_fecha'        => ['type' => 'DATE', 'null' => true],
            'prioridad'            => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'MEDIA'],
            'activo'               => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'clave_activa'         => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true, 'default' => 1],
            'observaciones'        => ['type' => 'TEXT', 'null' => true],
            'created_by'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'updated_by'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at'           => ['type' => 'DATETIME', 'null' => true],
            'updated_at'           => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'           => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['empresa_id', 'id']);
        $this->forge->addUniqueKey(['empresa_id', 'equipo_id', 'tipo_servicio_id', 'clave_activa']);
        $this->forge->addKey(['empresa_id', 'activo']);
        $this->forge->addKey(['empresa_id', 'proxima_fecha']);
        $this->forge->addKey(['empresa_id', 'proximo_km']);
        $this->forge->addKey(['empresa_id', 'proximas_horas']);
        $this->forge->addKey('tipo_servicio_id');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey(['empresa_id', 'equipo_id'], 'equipos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('tipo_servicio_id', 'tipos_servicio', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('planes_mantenimiento');
    }

    public function down(): void
    {
        $this->forge->dropTable('planes_mantenimiento');
    }
}
