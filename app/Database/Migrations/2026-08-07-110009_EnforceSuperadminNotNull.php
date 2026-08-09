<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class EnforceSuperadminNotNull extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('usuarios', [
            'es_superadmin' => [
                'name'       => 'es_superadmin',
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
                'comment'    => '1=alcance global; empresa_id debe ser NULL',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->modifyColumn('usuarios', [
            'es_superadmin' => [
                'name'       => 'es_superadmin',
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => true,
                'default'    => 0,
                'comment'    => '1=alcance global; empresa_id debe ser NULL',
            ],
        ]);
    }
}
