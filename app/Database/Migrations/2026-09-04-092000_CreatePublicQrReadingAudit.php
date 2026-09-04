<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreatePublicQrReadingAudit extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
            'token_id'=>['type'=>'INT','unsigned'=>true],
            'request_key'=>['type'=>'CHAR','constraint'=>36],
            'ip_hash'=>['type'=>'CHAR','constraint'=>64],
            'user_agent'=>['type'=>'VARCHAR','constraint'=>255,'null'=>true],
            'resultado'=>['type'=>'VARCHAR','constraint'=>20],
            'motivo'=>['type'=>'VARCHAR','constraint'=>255,'null'=>true],
            'lectura_id'=>['type'=>'BIGINT','unsigned'=>true,'null'=>true],
            'created_at'=>['type'=>'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('request_key');
        $this->forge->addKey(['token_id','created_at']);
        $this->forge->createTable('qr_lecturas_auditoria', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('qr_lecturas_auditoria', true);
    }
}
