<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddCompanyNotificationLocale extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('idioma_notificaciones', 'empresas')) {
            return;
        }

        $this->forge->addColumn('empresas', [
            'idioma_notificaciones' => [
                'type' => 'VARCHAR',
                'constraint' => 2,
                'null' => false,
                'default' => 'ES',
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('idioma_notificaciones', 'empresas')) {
            $this->forge->dropColumn('empresas', 'idioma_notificaciones');
        }
    }
}
