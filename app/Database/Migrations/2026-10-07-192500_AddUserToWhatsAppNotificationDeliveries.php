<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddUserToWhatsAppNotificationDeliveries extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('notificacion_whatsapp_entregas', [
            'usuario_id' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
                'after' => 'empleado_id',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('notificacion_whatsapp_entregas', 'usuario_id');
    }
}
