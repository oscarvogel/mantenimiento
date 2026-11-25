<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Colocaciones de neumáticos en equipos (especificación §5.12).
 *
 * Decisión: una sola tabla, una fila por colocación con su extracción cuando
 * la hay. La spec separa `neumatico_ubicaciones` (dónde está hoy) de
 * `neumatico_colocaciones` (el historial); acá la ubicación actual es la fila
 * con `activa = 1`. Así no hay dos tablas que puedan contradecirse y el
 * historial no se borra.
 *
 * `activa` vale 1 mientras el neumático está colocado y NULL después. Como
 * MySQL, MariaDB y SQLite admiten varios NULL en un índice único, los dos
 * índices únicos expresan en la base las invariantes:
 * - un neumático está colocado en un solo lugar a la vez;
 * - una posición de un equipo la ocupa un solo neumático.
 *
 * La posición usa las columnas del sistema anterior: lugar = eje, lado
 * (I/D), posición (E exterior, I interior, S simple) y botón.
 */
final class CreateNeumaticoColocaciones extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'neumatico_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'equipo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'lugar' => ['type' => 'VARCHAR', 'constraint' => 5],
            'lado' => ['type' => 'VARCHAR', 'constraint' => 1],
            'posicion' => ['type' => 'VARCHAR', 'constraint' => 1],
            'boton' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'fecha_colocacion' => ['type' => 'DATETIME'],
            'km_colocacion' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'fecha_extraccion' => ['type' => 'DATETIME', 'null' => true],
            'km_extraccion' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'activa' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['neumatico_id', 'activa'], 'uq_neumatico_colocaciones_activa');
        $this->forge->addUniqueKey(['empresa_id', 'equipo_id', 'lugar', 'lado', 'posicion', 'activa'], 'uq_neumatico_colocaciones_posicion');
        $this->forge->addKey(['empresa_id', 'neumatico_id', 'fecha_colocacion'], false, false, 'idx_neumatico_colocaciones_neumatico');
        $this->forge->addKey(['empresa_id', 'equipo_id', 'activa'], false, false, 'idx_neumatico_colocaciones_equipo');
        $this->forge->addForeignKey(['empresa_id', 'neumatico_id'], 'neumaticos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_neumatico_colocaciones_neumatico'));
        $this->forge->addForeignKey(['empresa_id', 'equipo_id'], 'equipos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', $this->fk('fk_neumatico_colocaciones_equipo'));
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_neumatico_colocaciones_created_by'));
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT', $this->fk('fk_neumatico_colocaciones_updated_by'));
        $this->forge->createTable('neumatico_colocaciones', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('neumatico_colocaciones', true);
    }

    /**
     * SQLite no acepta nombres de foreign key; MySQL sí.
     */
    private function fk(string $name): string
    {
        return $this->db->DBDriver === 'SQLite3' ? '' : $name;
    }
}
