<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddLiquidationPercentageToTripAssignments extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('viaje_operativo_asignaciones', [
            'porcentaje_liquidacion' => [
                'type' => 'DECIMAL',
                'constraint' => '5,2',
                'null' => true,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('viaje_operativo_asignaciones', 'porcentaje_liquidacion');
    }
}
