<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class EnableOperationalTripCostMaintenance extends Migration
{
    private const TABLES = [
        'viaje_operativo_combustible',
        'viaje_operativo_gastos',
        'viaje_operativo_caja_movimientos',
    ];

    private const COLUMNS = [
        'updated_at' => ['type' => 'DATETIME', 'null' => true],
        'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        'deleted_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $missingColumns = array_filter(
                self::COLUMNS,
                fn (array $definition, string $column): bool => ! $this->columnExists($table, $column),
                ARRAY_FILTER_USE_BOTH,
            );
            if ($missingColumns !== []) {
                $this->forge->addColumn($table, $missingColumns);
            }

            if ($this->db->DBDriver !== 'SQLite3') {
                $this->addForeignKeyIfMissing($table, 'updated_by');
                $this->addForeignKeyIfMissing($table, 'deleted_by');
            }
        }

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'viaje_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'movimiento_tipo' => ['type' => 'VARCHAR', 'constraint' => 20],
            'movimiento_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'accion' => ['type' => 'VARCHAR', 'constraint' => 20],
            'datos_anteriores' => ['type' => 'TEXT', 'null' => true],
            'datos_nuevos' => ['type' => 'TEXT', 'null' => true],
            'usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['empresa_id', 'viaje_id', 'created_at'], false, false, 'idx_viaje_operativo_mov_audit');
        $this->forge->addKey(['empresa_id', 'movimiento_tipo', 'movimiento_id'], false, false, 'idx_viaje_operativo_mov_audit_mov');
        $this->addAuditForeignKey('empresa_id', 'empresas', 'RESTRICT', 'RESTRICT', 'fk_viaje_operativo_mov_audit_empresa');
        $this->addAuditForeignKey('sucursal_id', 'sucursales', 'RESTRICT', 'RESTRICT', 'fk_viaje_operativo_mov_audit_sucursal');
        $this->addAuditForeignKey('viaje_id', 'viajes_operativos', 'RESTRICT', 'RESTRICT', 'fk_viaje_operativo_mov_audit_viaje');
        $this->addAuditForeignKey('usuario_id', 'usuarios', 'SET NULL', 'RESTRICT', 'fk_viaje_operativo_mov_audit_usuario');
        $this->forge->createTable('viaje_operativo_movimientos_auditoria', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('viaje_operativo_movimientos_auditoria', true);

        foreach (self::TABLES as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }

            if ($this->db->DBDriver !== 'SQLite3') {
                $this->dropForeignKeyIfExists($table, 'fk_' . $table . '_updated_by');
                $this->dropForeignKeyIfExists($table, 'fk_' . $table . '_deleted_by');
            }

            $existingColumns = array_values(array_filter(
                array_keys(self::COLUMNS),
                fn (string $column): bool => $this->columnExists($table, $column),
            ));
            if ($existingColumns !== []) {
                $this->forge->dropColumn($table, $existingColumns);
            }
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

    private function addAuditForeignKey(string $column, string $referencedTable, string $onUpdate, string $onDelete, string $name): void
    {
        if ($this->db->DBDriver === 'SQLite3') {
            $this->forge->addForeignKey($column, $referencedTable, 'id', $onUpdate, $onDelete);
            return;
        }

        $this->forge->addForeignKey($column, $referencedTable, 'id', $onUpdate, $onDelete, $name);
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
        $table = $this->db->prefixTable($table);
        if ($this->db->DBDriver === 'SQLite3') {
            foreach ($this->db->query('PRAGMA table_info(' . $this->db->escape($table) . ')')->getResultArray() as $field) {
                if (($field['name'] ?? null) === $column) {
                    return true;
                }
            }

            return false;
        }

        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column],
        )->getRowArray() !== null;
    }
}
