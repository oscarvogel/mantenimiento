<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddWhatsAppProviderMessageId extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('notificacion_whatsapp_entregas')) {
            return;
        }

        if (! $this->db->fieldExists('provider_message_id', 'notificacion_whatsapp_entregas')) {
            $this->forge->addColumn('notificacion_whatsapp_entregas', [
                'provider_message_id' => [
                    'type' => 'VARCHAR',
                    'constraint' => 191,
                    'null' => true,
                    'after' => 'gateway_message_id',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('notificacion_whatsapp_entregas')
            && $this->db->fieldExists('provider_message_id', 'notificacion_whatsapp_entregas')) {
            $this->forge->dropColumn('notificacion_whatsapp_entregas', 'provider_message_id');
        }
    }
}
