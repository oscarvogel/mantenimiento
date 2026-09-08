<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddEmployeePhoto extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('foto_path', 'empleados')) {
            $this->forge->addColumn('empleados', [
                'foto_path' => [
                    'type' => 'VARCHAR',
                    'constraint' => 255,
                    'null' => true,
                    'after' => 'observaciones',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('foto_path', 'empleados')) {
            $this->forge->dropColumn('empleados', 'foto_path');
        }
    }
}
