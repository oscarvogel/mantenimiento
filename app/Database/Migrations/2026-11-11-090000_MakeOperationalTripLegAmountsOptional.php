<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class MakeOperationalTripLegAmountsOptional extends Migration
{
    private const COLUMNS = [
        'flete_facturacion',
        'flete_facturacion_ars',
        'flete_liquidacion',
        'flete_liquidacion_ars',
    ];

    public function up(): void
    {
        $fields = [];
        foreach (self::COLUMNS as $column) {
            $fields[$column] = [
                'name' => $column,
                'type' => 'DECIMAL',
                'constraint' => '18,4',
                'null' => true,
            ];
        }

        $this->forge->modifyColumn('viaje_operativo_tramos', $fields);
    }

    public function down(): void
    {
        $table = $this->db->protectIdentifiers('viaje_operativo_tramos', true);
        $conditions = array_map(
            static fn (string $column): string => $column . ' IS NULL',
            self::COLUMNS,
        );
        $nullAmounts = (int) $this->db->query(
            'SELECT COUNT(*) AS total FROM ' . $table . ' WHERE ' . implode(' OR ', $conditions),
        )->getRowArray()['total'];
        if ($nullAmounts > 0) {
            throw new RuntimeException('down() no puede volver obligatorios los fletes mientras haya tramos sin importe.');
        }

        $fields = [];
        foreach (self::COLUMNS as $column) {
            $fields[$column] = [
                'name' => $column,
                'type' => 'DECIMAL',
                'constraint' => '18,4',
                'null' => false,
                'default' => 0,
            ];
        }

        $this->forge->modifyColumn('viaje_operativo_tramos', $fields);
    }
}
