<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddManagementReportSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('empresas', [
            'emails_informes' => [
                'type' => 'VARCHAR',
                'constraint' => 1000,
                'null' => true,
                'after' => 'notificaciones_email_habilitadas',
            ],
            'informe_diario_habilitado' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'default' => 0,
                'after' => 'emails_informes',
            ],
            'informe_diario_hora' => [
                'type' => 'CHAR',
                'constraint' => 5,
                'default' => '07:00',
                'after' => 'informe_diario_habilitado',
            ],
            'informe_semanal_habilitado' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'default' => 0,
                'after' => 'informe_diario_hora',
            ],
            'informe_semanal_dia' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'default' => 1,
                'after' => 'informe_semanal_habilitado',
            ],
            'informe_semanal_hora' => [
                'type' => 'CHAR',
                'constraint' => 5,
                'default' => '07:00',
                'after' => 'informe_semanal_dia',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('empresas', [
            'emails_informes',
            'informe_diario_habilitado',
            'informe_diario_hora',
            'informe_semanal_habilitado',
            'informe_semanal_dia',
            'informe_semanal_hora',
        ]);
    }
}
