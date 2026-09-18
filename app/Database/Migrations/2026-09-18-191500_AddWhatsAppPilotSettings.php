<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddWhatsAppPilotSettings extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('configuracion_canales_globales')) {
            return;
        }

        if (! $this->db->fieldExists('whatsapp_pilot_enabled', 'configuracion_canales_globales')) {
            $this->forge->addColumn('configuracion_canales_globales', [
                'whatsapp_pilot_enabled' => [
                    'type' => 'TINYINT',
                    'constraint' => 1,
                    'default' => 1,
                    'after' => 'whatsapp_instance_id',
                ],
            ]);
        }

        if (! $this->db->fieldExists('whatsapp_pilot_phone', 'configuracion_canales_globales')) {
            $this->forge->addColumn('configuracion_canales_globales', [
                'whatsapp_pilot_phone' => [
                    'type' => 'VARCHAR',
                    'constraint' => 50,
                    'null' => true,
                    'after' => 'whatsapp_pilot_enabled',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('configuracion_canales_globales')) {
            return;
        }

        if ($this->db->fieldExists('whatsapp_pilot_phone', 'configuracion_canales_globales')) {
            $this->forge->dropColumn('configuracion_canales_globales', 'whatsapp_pilot_phone');
        }

        if ($this->db->fieldExists('whatsapp_pilot_enabled', 'configuracion_canales_globales')) {
            $this->forge->dropColumn('configuracion_canales_globales', 'whatsapp_pilot_enabled');
        }
    }
}
