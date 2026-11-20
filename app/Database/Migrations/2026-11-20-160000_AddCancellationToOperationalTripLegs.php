<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Anulación de tramos de un viaje operativo.
 *
 * El tramo anulado no se borra: conserva sus datos y queda marcado con la
 * fecha, el usuario y el motivo. Deja de contar en fletes, comisión, margen,
 * liquidación del chofer y cuenta corriente de clientes.
 */
final class AddCancellationToOperationalTripLegs extends Migration
{
    private const TABLE = 'viaje_operativo_tramos';

    public function up(): void
    {
        $columns = [
            'anulado_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'observaciones'],
            'anulado_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'anulado_at'],
            'motivo_anulacion' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'after' => 'anulado_por'],
        ];
        foreach ($columns as $name => $definition) {
            // Forge cachea los nombres de columna: se invalida para que la
            // comprobación de existencia vea el estado real de la tabla.
            $this->db->resetDataCache();
            if (! $this->db->fieldExists($name, self::TABLE)) {
                $this->forge->addColumn(self::TABLE, [$name => $definition]);
            }
        }
        $this->db->resetDataCache();
    }

    public function down(): void
    {
        $this->db->resetDataCache();
        if (! $this->db->fieldExists('anulado_at', self::TABLE)) {
            return;
        }
        $table = $this->db->protectIdentifiers(self::TABLE, true);
        $cancelled = (int) $this->db->query(
            'SELECT COUNT(*) AS total FROM ' . $table . ' WHERE anulado_at IS NOT NULL',
        )->getRowArray()['total'];
        if ($cancelled > 0) {
            throw new RuntimeException('down() no puede borrar la anulación de tramos mientras haya tramos anulados.');
        }

        foreach (['motivo_anulacion', 'anulado_por', 'anulado_at'] as $column) {
            if (in_array($column, $this->db->getFieldNames(self::TABLE), true)) {
                $this->forge->dropColumn(self::TABLE, $column);
            }
        }
    }
}
