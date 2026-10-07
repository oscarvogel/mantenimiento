<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddPhoneToUsuarios extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('usuarios', [
            'telefono' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'null' => true,
                'after' => 'email',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('usuarios', 'telefono');
    }
}
