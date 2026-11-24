<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Porcentaje de liquidación por defecto de la empresa.
 *
 * El porcentaje del chofer sale de la copia guardada en el viaje o, si no
 * hay, de la fila vigente del maestro de porcentajes. Ese maestro se carga por
 * lotes: un chofer que se da de alta después de la última carga no tiene fila
 * y sus viajes quedan sin comisión ("Sin datos").
 *
 * Esta tabla guarda el respaldo de la empresa: un único porcentaje que se
 * aplica cuando ni el viaje ni el maestro tienen un valor. Sin fila, el
 * comportamiento es el de siempre (el porcentaje queda sin definir).
 */
final class CreateDriverLiquidationDefault extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('viajes_porcentaje_liquidacion_defecto')) {
            return;
        }

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'porcentaje' => ['type' => 'DECIMAL', 'constraint' => '5,2'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        // Un solo porcentaje por empresa: es la regla, no un historial.
        $this->forge->addUniqueKey('empresa_id', 'uq_viajes_porcentaje_defecto_empresa');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT', $this->fk('fk_viajes_porcentaje_defecto_empresa'));
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_viajes_porcentaje_defecto_autor'));
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_viajes_porcentaje_defecto_editor'));
        $this->forge->createTable('viajes_porcentaje_liquidacion_defecto', true);
    }

    public function down(): void
    {
        if ($this->db->tableExists('viajes_porcentaje_liquidacion_defecto')) {
            $this->forge->dropTable('viajes_porcentaje_liquidacion_defecto', true);
        }
    }

    /** SQLite (pruebas) no admite nombres de foreign key; MySQL los conserva. */
    private function fk(string $name): string
    {
        return $this->db->DBDriver === 'SQLite3' ? '' : $name;
    }
}
