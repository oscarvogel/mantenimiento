<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class LinkEquipmentAttachmentsToWorkOrders extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('orden_id', 'equipo_adjuntos')) {
            $this->forge->addColumn('equipo_adjuntos', [
                'orden_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                    'after' => 'equipo_id',
                ],
            ]);
        }

        $this->db->query('CREATE INDEX idx_equipo_adjuntos_orden ON equipo_adjuntos (empresa_id, orden_id)');
    }

    public function down(): void
    {
        if ($this->db->fieldExists('orden_id', 'equipo_adjuntos')) {
            $this->db->query('DROP INDEX idx_equipo_adjuntos_orden ON equipo_adjuntos');
            $this->forge->dropColumn('equipo_adjuntos', 'orden_id');
        }
    }
}
