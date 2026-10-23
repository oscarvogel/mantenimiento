<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Facturacion historica del legacy de transporte.
 *
 * Las facturas se guardan como historia de solo lectura. No se emiten comprobantes
 * ni se calculan impuestos: se preserva el comprobante original y su detalle para
 * poder vincularlo a un viaje y analizar rentabilidad.
 *
 * El legacy no trae una clave factura-viaje. El vinculo se guarda aparte, con
 * confianza y estado, para que una persona pueda revisarlo antes de usarlo en el
 * analisis. Una factura tiene como maximo un vinculo vigente.
 */
final class CreateTripInvoices extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'importacion_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'clave_legacy' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tipo_comprobante' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'desc_tipo' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'punto_venta' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'numero_comprobante' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'fecha' => ['type' => 'DATE'],
            'cuit_origen' => ['type' => 'CHAR', 'constraint' => 13, 'null' => true],
            'codigo_cliente_origen' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'moneda' => ['type' => 'CHAR', 'constraint' => 5, 'null' => true],
            'cotizacion' => ['type' => 'DECIMAL', 'constraint' => '18,6', 'null' => true],
            'subtotal' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'iva' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'impuestos' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'dgr' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'total' => ['type' => 'DECIMAL', 'constraint' => '18,4'],
            'total_ars' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'signo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'cae' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'clave_legacy'], 'uq_viajes_facturas_clave_legacy');
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_viajes_facturas_empresa_id');
        $this->forge->addKey(['empresa_id', 'fecha'], false, false, 'idx_viajes_facturas_empresa_fecha');
        $this->forge->addKey(['empresa_id', 'codigo_cliente_origen'], false, false, 'idx_viajes_facturas_cliente');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('importacion_id', 'importaciones', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('viajes_facturas', true);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'factura_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'item' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 200],
            'cantidad' => ['type' => 'DECIMAL', 'constraint' => '14,4', 'null' => true],
            'precio' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true],
            'iva' => ['type' => 'DECIMAL', 'constraint' => '10,4', 'null' => true],
            'codigo_articulo' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'codigo_alternativo' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'unidad_origen' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'origen_texto' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'destino_texto' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'remitos_texto' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['factura_id', 'item'], 'uq_viaje_factura_lineas_item');
        $this->forge->addKey(['empresa_id', 'unidad_origen'], false, false, 'idx_viaje_factura_lineas_unidad');
        $this->forge->addForeignKey('factura_id', 'viajes_facturas', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('viaje_factura_lineas', true);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'factura_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'viaje_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'SIN_VINCULO'],
            'confianza' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'puntaje' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'metodo' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'motivo' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('factura_id', 'uq_viaje_factura_viaje_factura');
        $this->forge->addKey(['empresa_id', 'viaje_id'], false, false, 'idx_viaje_factura_viaje_viaje');
        $this->forge->addKey(['empresa_id', 'estado'], false, false, 'idx_viaje_factura_viaje_estado');
        $this->forge->addForeignKey('factura_id', 'viajes_facturas', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('viaje_id', 'viajes', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('viaje_factura_viaje', true);
    }

    public function down(): void
    {
        foreach (['viaje_factura_viaje', 'viaje_factura_lineas', 'viajes_facturas'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
