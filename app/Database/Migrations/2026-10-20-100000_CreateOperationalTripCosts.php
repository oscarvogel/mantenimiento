<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Costos y caja del viaje operativo.
 *
 * - combustible: litros, importe y odometro para calcular consumo.
 * - gastos: peajes, viaticos, carga/descarga y otros, con categoria y medio de pago.
 * - caja: anticipos, retiros y devoluciones del chofer. No son costo del viaje.
 */
final class CreateOperationalTripCosts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'viaje_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'fecha' => ['type' => 'DATETIME'],
            'litros' => ['type' => 'DECIMAL', 'constraint' => '14,2'],
            'importe' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
            'moneda' => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'ARS'],
            'cotizacion' => ['type' => 'DECIMAL', 'constraint' => '18,6', 'default' => 1],
            'importe_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
            'kilometraje' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'estacion' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'comprobante' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'observaciones' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['empresa_id', 'viaje_id', 'fecha'], false, false, 'idx_viaje_operativo_combustible');
        $this->forge->addForeignKey('viaje_id', 'viajes_operativos', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('viaje_operativo_combustible', true);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'viaje_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'categoria_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'fecha' => ['type' => 'DATETIME'],
            'importe' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
            'moneda' => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'ARS'],
            'cotizacion' => ['type' => 'DECIMAL', 'constraint' => '18,6', 'default' => 1],
            'importe_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
            'medio_pago_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'pagado_de_caja' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'comprobante' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'observaciones' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['empresa_id', 'viaje_id', 'fecha'], false, false, 'idx_viaje_operativo_gastos');
        $this->forge->addForeignKey('viaje_id', 'viajes_operativos', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('categoria_id', 'viajes_categorias_gasto', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('medio_pago_id', 'viajes_medios_pago', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('viaje_operativo_gastos', true);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'viaje_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tipo' => ['type' => 'VARCHAR', 'constraint' => 20],
            'fecha' => ['type' => 'DATETIME'],
            'importe' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
            'moneda' => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'ARS'],
            'cotizacion' => ['type' => 'DECIMAL', 'constraint' => '18,6', 'default' => 1],
            'importe_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
            'observaciones' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['empresa_id', 'viaje_id', 'fecha'], false, false, 'idx_viaje_operativo_caja');
        $this->forge->addForeignKey('viaje_id', 'viajes_operativos', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('viaje_operativo_caja_movimientos', true);
    }

    public function down(): void
    {
        foreach (['viaje_operativo_caja_movimientos', 'viaje_operativo_gastos', 'viaje_operativo_combustible'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
