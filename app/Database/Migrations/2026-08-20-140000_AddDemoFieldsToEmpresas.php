<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddDemoFieldsToEmpresas extends Migration
{
    public function up(): void
    {
        $columns = [];

        if (! $this->db->fieldExists('es_demo', 'empresas')) {
            $columns['es_demo'] = [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
                'null' => false,
                'after' => 'estado',
            ];
        }

        if (! $this->db->fieldExists('demo_expira_at', 'empresas')) {
            $columns['demo_expira_at'] = [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'es_demo',
            ];
        }

        if ($columns !== []) {
            $this->forge->addColumn('empresas', $columns);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('demo_expira_at', 'empresas')) {
            $this->forge->dropColumn('empresas', 'demo_expira_at');
        }
        if ($this->db->fieldExists('es_demo', 'empresas')) {
            $this->forge->dropColumn('empresas', 'es_demo');
        }
    }
}
