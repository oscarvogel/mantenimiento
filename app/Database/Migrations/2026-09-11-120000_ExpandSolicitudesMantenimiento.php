<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class ExpandSolicitudesMantenimiento extends Migration
{
    public function up(): void
    {
        $fields = [];
        if (! $this->db->fieldExists('prioridad', 'solicitudes_mantenimiento')) {
            $fields['prioridad'] = ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'MEDIA'];
        }
        if (! $this->db->fieldExists('motivo_resolucion', 'solicitudes_mantenimiento')) {
            $fields['motivo_resolucion'] = ['type' => 'TEXT', 'null' => true];
        }
        if (! $this->db->fieldExists('revisado_por', 'solicitudes_mantenimiento')) {
            $fields['revisado_por'] = ['type' => 'INT', 'unsigned' => true, 'null' => true];
        }
        if (! $this->db->fieldExists('revisado_at', 'solicitudes_mantenimiento')) {
            $fields['revisado_at'] = ['type' => 'DATETIME', 'null' => true];
        }
        if (! $this->db->fieldExists('agrupada_en_id', 'solicitudes_mantenimiento')) {
            $fields['agrupada_en_id'] = ['type' => 'INT', 'unsigned' => true, 'null' => true];
        }
        if ($fields !== []) $this->forge->addColumn('solicitudes_mantenimiento', $fields);

        // addKey()/addForeignKey() solo dejan definiciones pendientes para
        // createTable(). Como esta migración amplía una tabla existente,
        // los índices y constraints deben aplicarse con ALTER TABLE.
        if ($this->db->DBDriver !== 'MySQLi') return;

        $this->addIndexIfMissing('idx_solicitudes_scope_estado_fecha', ['empresa_id', 'sucursal_id', 'estado', 'fecha_reporte']);
        $this->addForeignKeyIfMissing('fk_solicitudes_revisor', 'revisado_por', 'usuarios', 'id');
        $this->addForeignKeyIfMissing('fk_solicitudes_agrupada', 'agrupada_en_id', 'solicitudes_mantenimiento', 'id');
    }

    public function down(): void
    {
        if (! $this->db->tableExists('solicitudes_mantenimiento')) return;
        $this->dropForeignKeyIfExists('solicitudes_mantenimiento', 'fk_solicitudes_revisor');
        $this->dropForeignKeyIfExists('solicitudes_mantenimiento', 'fk_solicitudes_agrupada');
        $columns = array_values(array_filter(['prioridad', 'motivo_resolucion', 'revisado_por', 'revisado_at', 'agrupada_en_id'], fn (string $column): bool => $this->db->fieldExists($column, 'solicitudes_mantenimiento')));
        if ($columns !== []) $this->forge->dropColumn('solicitudes_mantenimiento', $columns);
        if ($this->db->DBDriver === 'MySQLi') {
            $this->dropIndexIfExists('solicitudes_mantenimiento', 'idx_solicitudes_scope_estado_fecha');
        }
    }

    private function addIndexIfMissing(string $name, array $columns): void
    {
        $indexes = $this->db->getIndexData('solicitudes_mantenimiento');
        $names = is_array($indexes) ? array_column($indexes, 'name') : [];
        if (! in_array($name, $names, true)) {
            $quotedColumns = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
            $this->db->query('CREATE INDEX `' . $name . '` ON `solicitudes_mantenimiento` (' . $quotedColumns . ')');
        }
    }

    private function addForeignKeyIfMissing(string $name, string $column, string $table, string $reference): void
    {
        $keys = $this->db->getForeignKeyData('solicitudes_mantenimiento');
        foreach ($keys as $key) {
            if (($key->constraint_name ?? $key->name ?? '') === $name) return;
        }
        $this->db->query(
            'ALTER TABLE `solicitudes_mantenimiento` ADD CONSTRAINT `' . $name . '` '
            . 'FOREIGN KEY (`' . $column . '`) REFERENCES `' . $table . '` (`' . $reference . '`) '
            . 'ON DELETE SET NULL ON UPDATE RESTRICT'
        );
    }

    private function dropForeignKeyIfExists(string $table, string $name): void
    {
        $keys = $this->db->getForeignKeyData($table);
        foreach ($keys as $key) {
            if (($key->constraint_name ?? $key->name ?? '') === $name) {
                $this->db->query('ALTER TABLE `' . $table . '` DROP FOREIGN KEY `' . $name . '`');
                return;
            }
        }
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        $indexes = $this->db->getIndexData($table);
        $names = is_array($indexes) ? array_column($indexes, 'name') : [];
        if (in_array($name, $names, true)) {
            $this->db->query('ALTER TABLE `' . $table . '` DROP INDEX `' . $name . '`');
        }
    }
}
