<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateOperationalTripLegs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'viaje_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'orden' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'cliente_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'origen' => ['type' => 'VARCHAR', 'constraint' => 200],
            'destino' => ['type' => 'VARCHAR', 'constraint' => 200],
            'km' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => true],
            'moneda' => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'ARS'],
            'cotizacion' => ['type' => 'DECIMAL', 'constraint' => '18,6', 'default' => 1],
            'flete_facturacion' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'default' => 0],
            'flete_facturacion_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'default' => 0],
            'flete_liquidacion' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'default' => 0],
            'flete_liquidacion_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'default' => 0],
            'observaciones' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_viaje_operativo_tramos_empresa_id');
        $this->forge->addUniqueKey(['empresa_id', 'viaje_id', 'id'], 'uq_viaje_operativo_tramos_scope');
        $this->forge->addUniqueKey(['viaje_id', 'orden'], 'uq_viaje_operativo_tramos_orden');
        $this->forge->addKey(['empresa_id', 'sucursal_id'], false, false, 'idx_viaje_operativo_tramos_sucursal');
        $this->forge->addKey(['empresa_id', 'cliente_id'], false, false, 'idx_viaje_operativo_tramos_cliente');
        $this->forge->addForeignKey('viaje_id', 'viajes_operativos', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('cliente_id', 'clientes', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('viaje_operativo_tramos', true);

        foreach (['viaje_operativo_combustible', 'viaje_operativo_gastos'] as $table) {
            $this->forge->addColumn($table, [
                'tramo_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                    'after' => 'viaje_id',
                ],
            ]);
            $this->db->query('CREATE INDEX idx_' . $table . '_tramo ON ' . $this->db->prefixTable($table) . ' (empresa_id, viaje_id, tramo_id)');
            if ($this->db->DBDriver !== 'SQLite3') {
                $this->db->query(
                    'ALTER TABLE ' . $this->db->prefixTable($table)
                    . ' ADD CONSTRAINT fk_' . $table . '_tramo FOREIGN KEY (empresa_id, viaje_id, tramo_id) '
                    . 'REFERENCES ' . $this->db->prefixTable('viaje_operativo_tramos') . ' (empresa_id, viaje_id, id) '
                    . 'ON UPDATE CASCADE ON DELETE SET NULL',
                );
            }
        }
    }

    public function down(): void
    {
        foreach (['viaje_operativo_combustible', 'viaje_operativo_gastos'] as $table) {
            if ($this->db->DBDriver === 'SQLite3') {
                $this->db->query('DROP INDEX idx_' . $table . '_tramo');
            } else {
                $this->db->query('ALTER TABLE ' . $this->db->prefixTable($table) . ' DROP FOREIGN KEY fk_' . $table . '_tramo');
                $this->db->query('DROP INDEX idx_' . $table . '_tramo ON ' . $this->db->prefixTable($table));
            }
            $this->forge->dropColumn($table, 'tramo_id');
        }

        $this->forge->dropTable('viaje_operativo_tramos', true);
    }
}
