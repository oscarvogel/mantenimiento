<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddHistoricalCurrencyCostToWorkOrders extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('ordenes_trabajo', [
            'moneda_original' => [
                'type' => 'CHAR',
                'constraint' => 3,
                'null' => true,
                'after' => 'costo_total',
            ],
            'importe_original' => [
                'type' => 'DECIMAL',
                'constraint' => '15,2',
                'null' => true,
                'after' => 'moneda_original',
            ],
            'tipo_cambio_ars' => [
                'type' => 'DECIMAL',
                'constraint' => '18,6',
                'null' => true,
                'after' => 'importe_original',
            ],
            'fecha_tipo_cambio' => [
                'type' => 'DATE',
                'null' => true,
                'after' => 'tipo_cambio_ars',
            ],
            'origen_tipo_cambio' => [
                'type' => 'VARCHAR',
                'constraint' => 32,
                'null' => true,
                'after' => 'fecha_tipo_cambio',
            ],
            'importe_ars' => [
                'type' => 'DECIMAL',
                'constraint' => '15,2',
                'null' => true,
                'after' => 'origen_tipo_cambio',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('ordenes_trabajo', [
            'moneda_original',
            'importe_original',
            'tipo_cambio_ars',
            'fecha_tipo_cambio',
            'origen_tipo_cambio',
            'importe_ars',
        ]);
    }
}
