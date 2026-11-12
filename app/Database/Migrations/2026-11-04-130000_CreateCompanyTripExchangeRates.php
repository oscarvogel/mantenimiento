<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cotización vigente por moneda y empresa para Viajes.
 *
 * Las monedas siguen siendo un catálogo compartido (`viajes_monedas`). Cada
 * empresa define aquí su propia cotización a ARS. Los movimientos del viaje
 * conservan su propio snapshot, por lo que cambiar una cotización nunca
 * recalcula importes históricos.
 */
final class CreateCompanyTripExchangeRates extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'moneda' => ['type' => 'CHAR', 'constraint' => 3],
            'cotizacion' => ['type' => 'DECIMAL', 'constraint' => '18,6'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'moneda'], 'uq_viajes_cotizaciones_empresa_moneda');
        $this->forge->addKey(['empresa_id', 'updated_at'], false, false, 'idx_viajes_cotizaciones_empresa');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('viajes_cotizaciones', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('viajes_cotizaciones', true);
    }
}
