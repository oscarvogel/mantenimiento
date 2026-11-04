<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Vincula gastos y otros haberes con el catálogo de conceptos.
 *
 * La carga guarda un snapshot de la cantidad y del importe unitario para que
 * un cambio posterior de tarifa no altere viajes ya registrados.
 */
final class AddConceptsToOperationalTripCosts extends Migration
{
    private const TABLES = [
        'viaje_operativo_gastos' => 'categoria_id',
        'viaje_operativo_otros_haberes' => 'concepto',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $after) {
            $this->forge->addColumn($table, [
                'concepto_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                    'after' => $after,
                ],
                'cantidad' => [
                    'type' => 'DECIMAL',
                    'constraint' => '10,2',
                    'default' => 1,
                ],
                'importe_unitario' => [
                    'type' => 'DECIMAL',
                    'constraint' => '18,4',
                    'null' => true,
                ],
            ]);

            $index = 'idx_' . $table . '_concepto';
            if (! $this->indexExists($table, $index)) {
                $this->db->query('CREATE INDEX ' . $index . ' ON ' . $this->db->prefixTable($table) . ' (empresa_id, concepto_id)');
            }

            $constraint = 'fk_' . $table . '_concepto';
            if ($this->db->DBDriver !== 'SQLite3' && ! $this->constraintExists($table, $constraint)) {
                $this->db->query(
                    'ALTER TABLE ' . $this->db->prefixTable($table)
                    . ' ADD CONSTRAINT ' . $constraint . ' FOREIGN KEY (empresa_id, concepto_id) '
                    . 'REFERENCES ' . $this->db->prefixTable('viajes_conceptos') . ' (empresa_id, id) '
                    . 'ON UPDATE CASCADE ON DELETE RESTRICT',
                );
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            $constraint = 'fk_' . $table . '_concepto';
            if ($this->constraintExists($table, $constraint)) {
                $this->db->query('ALTER TABLE ' . $this->db->prefixTable($table) . ' DROP FOREIGN KEY ' . $constraint);
            }

            $index = 'idx_' . $table . '_concepto';
            if ($this->indexExists($table, $index)) {
                $this->db->query(
                    $this->db->DBDriver === 'SQLite3'
                        ? 'DROP INDEX ' . $index
                        : 'DROP INDEX ' . $index . ' ON ' . $this->db->prefixTable($table),
                );
            }

            foreach (['concepto_id', 'cantidad', 'importe_unitario'] as $column) {
                if (in_array($column, $this->db->getFieldNames($table), true)) {
                    $this->forge->dropColumn($table, $column);
                }
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        if ($this->db->DBDriver === 'SQLite3') {
            foreach ($this->db->query('PRAGMA index_list(' . $this->db->prefixTable($table) . ')')->getResultArray() as $row) {
                if (($row['name'] ?? '') === $index) {
                    return true;
                }
            }

            return false;
        }

        return $this->db->query(
            'SHOW INDEX FROM ' . $this->db->prefixTable($table) . ' WHERE Key_name = ?',
            [$index],
        )->getResultArray() !== [];
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        if ($this->db->DBDriver === 'SQLite3') {
            return false;
        }

        return $this->db->query(
            'SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [$this->db->prefixTable($table), $constraint],
        )->getRowArray() !== null;
    }
}
