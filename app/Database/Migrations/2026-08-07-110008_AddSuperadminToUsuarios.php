<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddSuperadminToUsuarios extends Migration
{
    public function up(): void
    {
        // El primer bootstrap de Ferozo incluyo una migracion anterior que ya
        // creo esta columna con otra version. Mantener este paso idempotente
        // permite actualizar esa instalacion sin debilitar las instalaciones
        // limpias ni manipular manualmente la tabla de migraciones.
        if ($this->db->fieldExists('es_superadmin', 'usuarios')) {
            return;
        }

        $this->forge->addColumn('usuarios', [
            'es_superadmin' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'after'      => 'password_hash',
                'comment'    => '1=alcance global; empresa_id debe ser NULL',
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('es_superadmin', 'usuarios')) {
            $this->forge->dropColumn('usuarios', 'es_superadmin');
        }
    }
}
