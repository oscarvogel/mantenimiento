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
        $this->forge->addKey(['empresa_id', 'usuario_id']);
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->processIndexes('notificacion_whatsapp_entregas');
    }

    public function down(): void
    {
        $this->forge->dropForeignKey('notificacion_whatsapp_entregas', 'notificacion_whatsapp_entregas_usuario_id_foreign');
        $this->forge->dropColumn('notificacion_whatsapp_entregas', 'usuario_id');
    }
}
