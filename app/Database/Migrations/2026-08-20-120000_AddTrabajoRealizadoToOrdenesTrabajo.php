<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddTrabajoRealizadoToOrdenesTrabajo extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('trabajo_realizado', 'ordenes_trabajo')) {
            $this->forge->addColumn('ordenes_trabajo', [
                'trabajo_realizado' => [
                    'type' => 'TEXT',
                    'null' => true,
                    'after' => 'diagnostico',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('trabajo_realizado', 'ordenes_trabajo')) {
            $this->forge->dropColumn('ordenes_trabajo', 'trabajo_realizado');
        }
    }
}
