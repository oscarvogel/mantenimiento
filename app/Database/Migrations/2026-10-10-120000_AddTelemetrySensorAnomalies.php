<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddTelemetrySensorAnomalies extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('telematia_ultima_lectura') || $this->db->fieldExists('anomalias_sensor', 'telematia_ultima_lectura')) {
            return;
        }

        $this->forge->addColumn('telematia_ultima_lectura', [
            'anomalias_sensor' => ['type' => 'TEXT', 'null' => true],
        ]);
    }

    public function down(): void
    {
        if ($this->db->tableExists('telematia_ultima_lectura') && $this->db->fieldExists('anomalias_sensor', 'telematia_ultima_lectura')) {
            $this->forge->dropColumn('telematia_ultima_lectura', 'anomalias_sensor');
        }
    }
}
