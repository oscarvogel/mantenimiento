<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class AddTripLegDates extends Migration
{
    private const COLUMNS = ['fecha_inicio', 'fecha_fin'];

    public function up(): void
    {
        // Forge cachea los nombres de columna: se invalida para que la
        // comprobacion de existencia vea el estado real de la tabla.
        $this->db->resetDataCache();
        if (! $this->db->fieldExists('fecha_inicio', 'viaje_operativo_tramos')) {
            $this->forge->addColumn('viaje_operativo_tramos', [
                'fecha_inicio' => ['type' => 'DATE', 'null' => true, 'after' => 'flete_liquidacion_ars'],
            ]);
            $this->db->resetDataCache();
        }
        $this->db->resetDataCache();
        if (! $this->db->fieldExists('fecha_fin', 'viaje_operativo_tramos')) {
            $this->forge->addColumn('viaje_operativo_tramos', [
                'fecha_fin' => ['type' => 'DATE', 'null' => true, 'after' => 'fecha_inicio'],
            ]);
            $this->db->resetDataCache();
        }
    }

    public function down(): void
    {
        $table = $this->db->protectIdentifiers('viaje_operativo_tramos', true);
        $conditions = array_map(
            static fn (string $column): string => $column . ' IS NOT NULL',
            self::COLUMNS,
        );
        $dated = (int) $this->db->query(
            'SELECT COUNT(*) AS total FROM ' . $table . ' WHERE ' . implode(' OR ', $conditions),
        )->getRowArray()['total'];
        if ($dated > 0) {
            throw new RuntimeException('down() no puede borrar las fechas de los tramos mientras haya tramos con fechas cargadas.');
        }

        foreach (self::COLUMNS as $column) {
            if (in_array($column, $this->db->getFieldNames('viaje_operativo_tramos'), true)) {
                $this->forge->dropColumn('viaje_operativo_tramos', $column);
            }
        }
    }
}
