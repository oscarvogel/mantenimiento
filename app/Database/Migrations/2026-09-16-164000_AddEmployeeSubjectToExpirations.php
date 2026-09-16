<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Completa instalaciones que registraron CreateExpirationTables antes del
 * soporte de vencimientos de empleados.
 */
final class AddEmployeeSubjectToExpirations extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('vencimientos')) {
            return;
        }

        if (! $this->db->fieldExists('empleado_id', 'vencimientos')) {
            $this->forge->addColumn('vencimientos', [
                'empleado_id' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'null' => true,
                    'after' => 'equipo_id',
                ],
            ]);
        }

        if ($this->db->DBDriver !== 'MySQLi' || ! $this->db->tableExists('empleados')) {
            return;
        }

        $this->addIndexIfMissing('idx_expiration_employee', ['empresa_id', 'empleado_id']);
        $this->addUniqueIndexIfMissing(
            'uq_expiration_employee_type_date',
            ['empresa_id', 'empleado_id', 'tipo_vencimiento_id', 'fecha_vencimiento'],
        );
        $this->addForeignKeyIfMissing('fk_expiration_employee', 'empleado_id', 'empleados', 'id');
    }

    /**
     * No se revierte automáticamente: esta migración puede haber sido
     * aplicada como no-op en una instalación que ya tenía la columna.
     */
    public function down(): void
    {
    }

    /** @param list<string> $columns */
    private function addIndexIfMissing(string $name, array $columns): void
    {
        $indexes = $this->db->getIndexData('vencimientos');
        $names = is_array($indexes) ? array_column($indexes, 'name') : [];
        if (in_array($name, $names, true)) {
            return;
        }

        $quotedColumns = implode(', ', array_map(
            static fn (string $column): string => '`' . $column . '`',
            $columns,
        ));
        $this->db->query('CREATE INDEX `' . $name . '` ON `vencimientos` (' . $quotedColumns . ')');
    }

    /** @param list<string> $columns */
    private function addUniqueIndexIfMissing(string $name, array $columns): void
    {
        $indexes = $this->db->getIndexData('vencimientos');
        $names = is_array($indexes) ? array_column($indexes, 'name') : [];
        if (in_array($name, $names, true)) {
            return;
        }

        $quotedColumns = implode(', ', array_map(
            static fn (string $column): string => '`' . $column . '`',
            $columns,
        ));
        $this->db->query('CREATE UNIQUE INDEX `' . $name . '` ON `vencimientos` (' . $quotedColumns . ')');
    }

    private function addForeignKeyIfMissing(string $name, string $column, string $table, string $reference): void
    {
        foreach ($this->db->getForeignKeyData('vencimientos') as $key) {
            if (($key->constraint_name ?? $key->name ?? '') === $name) {
                return;
            }
        }

        $this->db->query(
            'ALTER TABLE `vencimientos` ADD CONSTRAINT `' . $name . '` '
            . 'FOREIGN KEY (`' . $column . '`) REFERENCES `' . $table . '` (`' . $reference . '`) '
            . 'ON DELETE RESTRICT ON UPDATE RESTRICT',
        );
    }
}
