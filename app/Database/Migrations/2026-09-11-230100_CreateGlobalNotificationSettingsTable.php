<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateGlobalNotificationSettingsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'TINYINT', 'unsigned' => true],
            'smtp_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'smtp_protocol' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'smtp'],
            'smtp_host' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'smtp_port' => ['type' => 'INT', 'unsigned' => true, 'default' => 587],
            'smtp_user' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'smtp_pass_encrypted' => ['type' => 'TEXT', 'null' => true],
            'smtp_crypto' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'smtp_from_email' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'smtp_from_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'smtp_timeout' => ['type' => 'INT', 'unsigned' => true, 'default' => 10],
            'webpush_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'webpush_subject' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'webpush_public_key' => ['type' => 'TEXT', 'null' => true],
            'webpush_private_key_encrypted' => ['type' => 'TEXT', 'null' => true],
            'whatsapp_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'whatsapp_api_url' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'whatsapp_api_key_encrypted' => ['type' => 'TEXT', 'null' => true],
            'whatsapp_instance_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'updated_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('configuracion_canales_globales', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('configuracion_canales_globales', true);
    }
}
