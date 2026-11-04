<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Habilita la edición y la baja lógica de otros haberes del viaje.
 *
 * Sigue el mismo esquema que combustible, gastos y caja para que la
 * mantención de movimientos sea homogénea y quede auditada.
 */
final class EnableOtherEarningMaintenance extends Migration
{
    private const COLUMNS = [
        'updated_at' => ['type' => 'DATETIME', 'null' => true],
        'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        'deleted_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
    ];

    public function up(): void
    {
        $table = 'viaje_operativo_otros_haberes';
        $missing = array_filter(
            self::COLUMNS,
            fn (array $definition, string $column): bool => ! $this->columnExists($table, $column),
            ARRAY_FILTER_USE_BOTH,
        );
        if ($missing !== []) {
            $this->forge->addColumn($table, $missing);
        }

        if ($this->db->DBDriver !== 'SQLite3') {
            $this->addForeignKeyIfMissing($table, 'updated_by');
            $this->addForeignKeyIfMissing($table, 'deleted_by');
        }
    }

    public function down(): void
    {
        $table = 'viaje_operativo_otros_haberes';
        if (! $this->db->tableExists($table)) {
            return;
        }

        if ($this->db->DBDriver !== 'SQLite3') {
            $this->dropForeignKeyIfExists($table, 'fk_' . $table . '_updated_by');
            $this->dropForeignKeyIfExists($table, 'fk_' . $table . '_deleted_by');
        }

        $existing = array_values(array_filter(
            array_keys(self::COLUMNS),
            fn (string $column): bool => $this->columnExists($table, $column),
        ));
        if ($existing !== []) {
            $this->forge->dropColumn($table, $existing);
        }
    }

    private function addForeignKeyIfMissing(string $table, string $column): void
    {
        $name = 'fk_' . $table . '_' . $column;
        if ($this->foreignKeyExists($table, $name)) {
            return;
        }

        $this->forge->addForeignKey($column, 'usuarios', 'id', 'RESTRICT', 'SET NULL', $name);
        $this->forge->processIndexes($table);
    }

    private function dropForeignKeyIfExists(string $table, string $name): void
    {
        if ($this->foreignKeyExists($table, $name)) {
            $this->forge->dropForeignKey($table, $name);
        }
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

    private function columnExists(string $table, string $column): bool
    {
        $prefixed = $this->db->prefixTable($table);
        if ($this->db->DBDriver === 'SQLite3') {
            foreach ($this->db->query('PRAGMA table_info(' . $this->db->escape($prefixed) . ')')->getResultArray() as $field) {
                if (($field['name'] ?? null) === $column) {
                    return true;
                }
            }

            return false;
        }

        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$prefixed, $column],
        )->getRowArray() !== null;
    }
}
