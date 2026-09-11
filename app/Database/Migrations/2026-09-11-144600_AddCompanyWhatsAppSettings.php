<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddCompanyWhatsAppSettings extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('notificaciones_whatsapp_habilitadas', 'empresas')) {
            $this->forge->addColumn('empresas', [
                'notificaciones_whatsapp_habilitadas' => [
                    'type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'notificaciones_email_habilitadas',
                ],
            ]);
        }
        if (! $this->db->fieldExists('whatsapp_instance_id', 'empresas')) {
            $this->forge->addColumn('empresas', [
                'whatsapp_instance_id' => [
                    'type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'notificaciones_whatsapp_habilitadas',
                ],
            ]);
        }
        if ($this->db->tableExists('notificacion_whatsapp_entregas')
            && ! $this->db->fieldExists('instance_id', 'notificacion_whatsapp_entregas')) {
            $this->forge->addColumn('notificacion_whatsapp_entregas', [
                'instance_id' => [
                    'type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'telefono',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('notificacion_whatsapp_entregas')
            && $this->db->fieldExists('instance_id', 'notificacion_whatsapp_entregas')) {
            $this->forge->dropColumn('notificacion_whatsapp_entregas', 'instance_id');
        }
        if ($this->db->fieldExists('whatsapp_instance_id', 'empresas')) {
            $this->forge->dropColumn('empresas', 'whatsapp_instance_id');
        }
        if ($this->db->fieldExists('notificaciones_whatsapp_habilitadas', 'empresas')) {
            $this->forge->dropColumn('empresas', 'notificaciones_whatsapp_habilitadas');
        }
    }
}
