<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class MakeOperationalTripEstimatedReturnOptional extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('viajes_operativos', [
            'fecha_regreso_planificada' => [
                'name' => 'fecha_regreso_planificada',
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
    }

    public function down(): void
    {
        $table = $this->db->protectIdentifiers('viajes_operativos', true);
        $nullReturns = (int) $this->db->query(
            'SELECT COUNT(*) AS total FROM ' . $table . ' WHERE fecha_regreso_planificada IS NULL',
        )->getRowArray()['total'];
        if ($nullReturns > 0) {
            throw new RuntimeException('down() no puede volver obligatoria la fecha mientras haya viajes sin regreso estimado.');
        }

        $this->forge->modifyColumn('viajes_operativos', [
            'fecha_regreso_planificada' => [
                'name' => 'fecha_regreso_planificada',
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);
    }
}
