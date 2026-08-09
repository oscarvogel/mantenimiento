<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateEquipmentRelationsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'equipo_principal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'equipo_relacionado_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tipo_relacion' => ['type' => 'VARCHAR', 'constraint' => 30],
            'desde' => ['type' => 'DATETIME'],
            'hasta' => ['type' => 'DATETIME', 'null' => true],
            'usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'finalizado_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'observaciones' => ['type' => 'TEXT', 'null' => true],
            'observaciones_fin' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['empresa_id', 'equipo_principal_id', 'hasta'], false, false, 'idx_relaciones_principal');
        $this->forge->addKey(['empresa_id', 'equipo_relacionado_id', 'tipo_relacion', 'hasta'], false, false, 'idx_relaciones_incompatibilidad');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_relaciones_empresa');
        $this->forge->addForeignKey(['empresa_id', 'equipo_principal_id'], 'equipos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_relaciones_principal_tenant');
        $this->forge->addForeignKey(['empresa_id', 'equipo_relacionado_id'], 'equipos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_relaciones_relacionado_tenant');
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'RESTRICT', 'RESTRICT', 'fk_relaciones_usuario');
        $this->forge->addForeignKey('finalizado_por', 'usuarios', 'id', 'RESTRICT', 'SET NULL', 'fk_relaciones_finalizado_por');
        $this->forge->createTable('equipo_relaciones');
    }

    public function down(): void
    {
        $this->forge->dropTable('equipo_relaciones');
    }
}
