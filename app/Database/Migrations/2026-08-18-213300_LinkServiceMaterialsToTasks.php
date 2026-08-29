<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class LinkServiceMaterialsToTasks extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('tarea_id', 'tipo_servicio_materiales')) {
            $this->forge->addColumn('tipo_servicio_materiales', [
                'tarea_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'null' => true,
                    'after' => 'tipo_servicio_id',
                ],
            ]);
            $this->db->query('CREATE INDEX idx_servicio_material_tarea ON tipo_servicio_materiales (tarea_id)');
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('tarea_id', 'tipo_servicio_materiales')) {
            $this->forge->dropColumn('tipo_servicio_materiales', 'tarea_id');
        }
    }
}
