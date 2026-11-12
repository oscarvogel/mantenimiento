<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * El flete de liquidacion pasa a tener su propia moneda y cotizacion.
 *
 * Hasta ahora los dos fletes del tramo compartian la moneda y la cotizacion
 * del flete de facturacion. Con esta migracion el flete de liquidacion se
 * puede cargar en pesos aunque el de facturacion este en dolares o reales.
 */
final class AddTripLegLiquidationCurrency extends Migration
{
    private const CURRENCY_COLUMN = 'moneda_liquidacion';
    private const RATE_COLUMN = 'cotizacion_liquidacion';

    public function up(): void
    {
        // Forge cachea los nombres de columna: se invalida para que la
        // comprobacion de existencia vea el estado real de la tabla.
        $this->db->resetDataCache();
        if (! $this->db->fieldExists(self::CURRENCY_COLUMN, 'viaje_operativo_tramos')) {
            $this->forge->addColumn('viaje_operativo_tramos', [
                self::CURRENCY_COLUMN => ['type' => 'CHAR', 'constraint' => 3, 'null' => true, 'after' => 'cotizacion'],
            ]);
            $this->db->resetDataCache();
        }
        $this->db->resetDataCache();
        if (! $this->db->fieldExists(self::RATE_COLUMN, 'viaje_operativo_tramos')) {
            $this->forge->addColumn('viaje_operativo_tramos', [
                self::RATE_COLUMN => ['type' => 'DECIMAL', 'constraint' => '18,6', 'null' => true, 'after' => self::CURRENCY_COLUMN],
            ]);
            $this->db->resetDataCache();
        }

        // Los tramos existentes usaban la misma moneda y cotizacion para los
        // dos fletes: la liquidacion nace con esos mismos valores.
        $table = $this->db->protectIdentifiers('viaje_operativo_tramos', true);
        $this->db->query(
            'UPDATE ' . $table
            . ' SET ' . $this->db->protectIdentifiers(self::CURRENCY_COLUMN) . ' = ' . $this->db->protectIdentifiers('moneda')
            . ', ' . $this->db->protectIdentifiers(self::RATE_COLUMN) . ' = ' . $this->db->protectIdentifiers('cotizacion')
            . ' WHERE ' . $this->db->protectIdentifiers(self::CURRENCY_COLUMN) . ' IS NULL'
        );
    }

    public function down(): void
    {
        $table = $this->db->protectIdentifiers('viaje_operativo_tramos', true);
        $currency = $this->db->protectIdentifiers(self::CURRENCY_COLUMN);
        $invoiceCurrency = $this->db->protectIdentifiers('moneda');
        $different = (int) $this->db->query(
            'SELECT COUNT(*) AS total FROM ' . $table
            . ' WHERE ' . $currency . ' IS NOT NULL AND ' . $currency . ' <> ' . $invoiceCurrency,
        )->getRowArray()['total'];
        if ($different > 0) {
            throw new RuntimeException('down() no puede borrar la moneda de liquidacion mientras haya tramos con liquidacion en una moneda distinta a la de facturacion.');
        }

        foreach ([self::CURRENCY_COLUMN, self::RATE_COLUMN] as $column) {
            if (in_array($column, $this->db->getFieldNames('viaje_operativo_tramos'), true)) {
                $this->forge->dropColumn('viaje_operativo_tramos', $column);
            }
        }
    }
}
