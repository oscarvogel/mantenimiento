<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddTelemetrySensorDefinitions extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('telematia_ultima_lectura')
            && ! $this->db->fieldExists('sensores_configurados', 'telematia_ultima_lectura')) {
            $this->forge->addColumn('telematia_ultima_lectura', [
                'sensores_configurados' => ['type' => 'TEXT', 'null' => true],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('telematia_ultima_lectura')
            && $this->db->fieldExists('sensores_configurados', 'telematia_ultima_lectura')) {
            $this->forge->dropColumn('telematia_ultima_lectura', 'sensores_configurados');
        }
    }
}
