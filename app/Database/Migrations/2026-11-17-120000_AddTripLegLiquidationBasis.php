<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Cómo se acordó con el chofer la base de liquidación de cada tramo (#533).
 *
 * - `modo_liquidacion`: POR_TONELADA o MONTO_ACORDADO.
 * - `toneladas_liquidacion` y `precio_unitario_liquidacion`: solo para
 *   POR_TONELADA. Son del acuerdo con el chofer, no las toneladas
 *   operativas del tramo (`toneladas`), para que corregir la carga o la
 *   facturación no cambie una liquidación ya pactada.
 *
 * `flete_liquidacion` y `flete_liquidacion_ars` siguen siendo el importe que
 * leen comisión, cierre y PDF: en POR_TONELADA se calculan al guardar.
 *
 * Los tramos existentes se cargaron con un importe global: quedan como
 * MONTO_ACORDADO sin tocar ningún importe.
 */
final class AddTripLegLiquidationBasis extends Migration
{
    private const TABLE = 'viaje_operativo_tramos';

    public function up(): void
    {
        $columns = [
            'modo_liquidacion' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'cotizacion_liquidacion'],
            'toneladas_liquidacion' => ['type' => 'DECIMAL', 'constraint' => '14,3', 'null' => true, 'after' => 'modo_liquidacion'],
            'precio_unitario_liquidacion' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'null' => true, 'after' => 'toneladas_liquidacion'],
        ];
        foreach ($columns as $name => $definition) {
            // Forge cachea los nombres de columna: se invalida antes de cada
            // comprobación para ver el estado real de la tabla.
            $this->db->resetDataCache();
            if (! $this->db->fieldExists($name, self::TABLE)) {
                $this->forge->addColumn(self::TABLE, [$name => $definition]);
            }
        }
        $this->db->resetDataCache();

        $this->db->table(self::TABLE)
            ->where('modo_liquidacion', null)
            ->update(['modo_liquidacion' => 'MONTO_ACORDADO']);
    }

    public function down(): void
    {
        $this->db->resetDataCache();
        if (! $this->db->fieldExists('modo_liquidacion', self::TABLE)) {
            return;
        }
        $perTonne = $this->db->table(self::TABLE)->where('modo_liquidacion', 'POR_TONELADA')->countAllResults();
        if ($perTonne > 0) {
            throw new RuntimeException('down() no puede borrar el modo de liquidación mientras haya tramos liquidados por toneladas.');
        }

        foreach (['precio_unitario_liquidacion', 'toneladas_liquidacion', 'modo_liquidacion'] as $column) {
            $this->db->resetDataCache();
            if ($this->db->fieldExists($column, self::TABLE)) {
                $this->forge->dropColumn(self::TABLE, $column);
            }
        }
    }
}
