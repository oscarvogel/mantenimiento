<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Etapas observables del ciclo de automatizaciones.
 *
 * `ejecuciones_programadas` conserva el estado global del proceso y su bloqueo;
 * esta tabla registra qué etapa terminó, falló u omitió y con qué resumen
 * acotado. `empresa_id` es nullable sólo porque el ciclo y sus etapas globales
 * no pertenecen a una empresa; una etapa atribuible a empresa debe informarlo.
 */
final class CreateAutomationExecutionStages extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'proceso' => ['type' => 'VARCHAR', 'constraint' => 100],
            'clave_ejecucion' => ['type' => 'VARCHAR', 'constraint' => 160],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'EN_PROCESO'],
            'resumen' => ['type' => 'TEXT', 'null' => true],
            'error_seguro' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'fecha_inicio' => ['type' => 'DATETIME'],
            'fecha_fin' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['clave_ejecucion', 'proceso'], false, false, 'idx_automatizacion_etapa_clave');
        $this->forge->addKey(['empresa_id', 'fecha_inicio'], false, false, 'idx_automatizacion_etapa_empresa');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('automatizacion_etapas', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('automatizacion_etapas', true);
    }
}
