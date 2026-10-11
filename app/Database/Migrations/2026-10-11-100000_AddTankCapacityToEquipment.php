<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddTankCapacityToEquipment extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('capacidad_tanque_litros', 'equipos')) {
            $this->forge->addColumn('equipos', [
                'capacidad_tanque_litros' => [
                    'type' => 'INT',
                    'unsigned' => true,
                    'null' => true,
                    'after' => 'motor',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('capacidad_tanque_litros', 'equipos')) {
            $this->forge->dropColumn('equipos', 'capacidad_tanque_litros');
        }
    }
}
