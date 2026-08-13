<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class AllowGenericPreventiveTemplates extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('plantillas_mantenimiento')
            || ! $this->db->fieldExists('tipo_equipo_id', 'plantillas_mantenimiento')) {
            return;
        }

        $this->forge->modifyColumn('plantillas_mantenimiento', [
            'tipo_equipo_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
        ]);
    }

    public function down(): void
    {
        if (! $this->db->tableExists('plantillas_mantenimiento')
            || ! $this->db->fieldExists('tipo_equipo_id', 'plantillas_mantenimiento')) {
            return;
        }

        if ($this->db->table('plantillas_mantenimiento')->where('tipo_equipo_id', null)->countAllResults() > 0) {
            throw new RuntimeException('No se puede revertir: existen plantillas preventivas genericas.');
        }

        $this->forge->modifyColumn('plantillas_mantenimiento', [
            'tipo_equipo_id' => ['type' => 'INT', 'constraint' => 11, 'null' => false],
        ]);
    }
}
