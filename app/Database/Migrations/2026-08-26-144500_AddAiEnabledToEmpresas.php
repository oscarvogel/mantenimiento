<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddAiEnabledToEmpresas extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('ia_habilitada', 'empresas')) {
            $this->forge->addColumn('empresas', [
                'ia_habilitada' => [
                    'type' => 'TINYINT',
                    'constraint' => 1,
                    'default' => 0,
                    'null' => false,
                    'comment' => '1=funciones IA habilitadas, 0=deshabilitadas',
                    'after' => 'estado',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('ia_habilitada', 'empresas')) {
            $this->forge->dropColumn('empresas', 'ia_habilitada');
        }
    }
}
