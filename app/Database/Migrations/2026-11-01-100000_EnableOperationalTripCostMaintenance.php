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

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->forge->addColumn($table, [
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'deleted_at' => ['type' => 'DATETIME', 'null' => true],
                'deleted_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            ]);
            $this->forge->addForeignKey('updated_by', $table, 'usuarios', 'id', 'RESTRICT', 'SET NULL', 'fk_' . $table . '_updated_by');
            $this->forge->addForeignKey('deleted_by', $table, 'usuarios', 'id', 'RESTRICT', 'SET NULL', 'fk_' . $table . '_deleted_by');
            $this->forge->processIndexes($table);
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
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_viaje_operativo_mov_audit_empresa');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT', 'fk_viaje_operativo_mov_audit_sucursal');
        $this->forge->addForeignKey('viaje_id', 'viajes_operativos', 'id', 'RESTRICT', 'RESTRICT', 'fk_viaje_operativo_mov_audit_viaje');
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'SET NULL', 'RESTRICT', 'fk_viaje_operativo_mov_audit_usuario');
        $this->forge->createTable('viaje_operativo_movimientos_auditoria', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('viaje_operativo_movimientos_auditoria', true);

        foreach (self::TABLES as $table) {
            $this->forge->dropForeignKey($table, 'fk_' . $table . '_updated_by');
            $this->forge->dropForeignKey($table, 'fk_' . $table . '_deleted_by');
            $this->forge->dropColumn($table, ['updated_at', 'updated_by', 'deleted_at', 'deleted_by']);
        }
    }
}
