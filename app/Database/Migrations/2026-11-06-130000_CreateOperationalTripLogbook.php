<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Diario de bordo del chofer.
 *
 * Registra las ventanas de espera por etapa (carga, cada frontera y cliente),
 * calcula los días de estadía por diferencia de fechas y descuenta la
 * franquicia de cada etapa. El haber se genera automáticamente en
 * `viaje_operativo_otros_haberes` y queda vinculado por `diario_bordo_id`.
 */
final class CreateOperationalTripLogbook extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'viaje_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'empleado_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'concepto_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tipo' => ['type' => 'VARCHAR', 'constraint' => 20],
            'orden' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'ubicacion' => ['type' => 'VARCHAR', 'constraint' => 200],
            'llegada' => ['type' => 'DATETIME'],
            'salida' => ['type' => 'DATETIME', 'null' => true],
            'horas_libres' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 48],
            'dias_calculados' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'dias_ajustados' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],
            'motivo_ajuste' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'observaciones' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_viaje_operativo_diario_bordo_empresa_id');
        $this->forge->addUniqueKey(['viaje_id', 'orden'], 'uq_viaje_operativo_diario_bordo_orden');
        $this->forge->addKey(['empresa_id', 'viaje_id', 'llegada'], false, false, 'idx_viaje_operativo_diario_bordo_viaje');
        $this->forge->addKey(['empresa_id', 'empleado_id', 'llegada'], false, false, 'idx_viaje_operativo_diario_bordo_empleado');
        $this->forge->addForeignKey('viaje_id', 'viajes_operativos', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('empleado_id', 'empleados', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('deleted_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('viaje_operativo_diario_bordo', true);

        if ($this->db->DBDriver !== 'SQLite3') {
            $this->db->query(
                'ALTER TABLE ' . $this->db->prefixTable('viaje_operativo_diario_bordo')
                . ' ADD CONSTRAINT fk_viaje_operativo_diario_bordo_concepto FOREIGN KEY (empresa_id, concepto_id) '
                . 'REFERENCES ' . $this->db->prefixTable('viajes_conceptos') . ' (empresa_id, id) '
                . 'ON UPDATE CASCADE ON DELETE RESTRICT',
            );
        }

        if (! $this->columnExists('viaje_operativo_otros_haberes', 'diario_bordo_id')) {
            $this->forge->addColumn('viaje_operativo_otros_haberes', [
                'diario_bordo_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                    'after' => 'concepto_id',
                ],
            ]);
            $this->createIndex(
                'viaje_operativo_otros_haberes',
                'idx_viaje_operativo_otros_haberes_diario',
                ['empresa_id', 'diario_bordo_id'],
            );
        }

        if ($this->db->DBDriver !== 'SQLite3' && ! $this->foreignKeyExists('viaje_operativo_otros_haberes', 'fk_viaje_operativo_otros_haberes_diario')) {
            $this->db->query(
                'ALTER TABLE ' . $this->db->prefixTable('viaje_operativo_otros_haberes')
                . ' ADD CONSTRAINT fk_viaje_operativo_otros_haberes_diario '
                . 'FOREIGN KEY (diario_bordo_id) REFERENCES ' . $this->db->prefixTable('viaje_operativo_diario_bordo') . ' (id) '
                . 'ON UPDATE CASCADE ON DELETE SET NULL',
            );
        }
    }

    public function down(): void
    {
        if ($this->columnExists('viaje_operativo_otros_haberes', 'diario_bordo_id')) {
            if ($this->db->DBDriver !== 'SQLite3' && $this->foreignKeyExists('viaje_operativo_otros_haberes', 'fk_viaje_operativo_otros_haberes_diario')) {
                $this->db->query(
                    'ALTER TABLE ' . $this->db->prefixTable('viaje_operativo_otros_haberes')
                    . ' DROP FOREIGN KEY fk_viaje_operativo_otros_haberes_diario',
                );
            }
            $this->dropIndex('viaje_operativo_otros_haberes', 'idx_viaje_operativo_otros_haberes_diario');
            $this->forge->dropColumn('viaje_operativo_otros_haberes', 'diario_bordo_id');
        }

        $this->forge->dropTable('viaje_operativo_diario_bordo', true);
    }

    /** @param list<string> $columns */
    private function createIndex(string $table, string $index, array $columns): void
    {
        $prefix = $this->db->prefixTable($table);
        $list = implode(', ', $columns);
        $sql = $this->db->DBDriver === 'SQLite3'
            ? 'CREATE INDEX ' . $index . ' ON ' . $prefix . ' (' . $list . ')'
            : 'ALTER TABLE ' . $prefix . ' ADD INDEX ' . $index . ' (' . $list . ')';
        $this->db->query($sql);
    }

    private function dropIndex(string $table, string $index): void
    {
        if ($this->db->DBDriver === 'SQLite3') {
            $this->db->query('DROP INDEX IF EXISTS ' . $index);
            return;
        }

        $this->db->query('ALTER TABLE ' . $this->db->prefixTable($table) . ' DROP INDEX ' . $index);
    }

    private function columnExists(string $table, string $column): bool
    {
        $prefix = $this->db->prefixTable($table);
        if ($this->db->DBDriver === 'SQLite3') {
            foreach ($this->db->query('PRAGMA table_info(' . $this->db->escape($prefix) . ')')->getResultArray() as $field) {
                if (($field['name'] ?? null) === $column) {
                    return true;
                }
            }

            return false;
        }

        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$prefix, $column],
        )->getRowArray() !== null;
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        foreach ($this->db->getForeignKeyData($table) as $foreignKey) {
            if (($foreignKey->constraint_name ?? $foreignKey->name ?? '') === $name) {
                return true;
            }
        }

        return false;
    }
}
