<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddBranchNotificationLocale extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('idioma_notificaciones', 'sucursales')) {
            return;
        }

        $this->forge->addColumn('sucursales', [
            'idioma_notificaciones' => [
                'type' => 'VARCHAR',
                'constraint' => 2,
                'null' => true,
                'default' => null,
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('idioma_notificaciones', 'sucursales')) {
            $this->forge->dropColumn('sucursales', 'idioma_notificaciones');
        }
    }
}
