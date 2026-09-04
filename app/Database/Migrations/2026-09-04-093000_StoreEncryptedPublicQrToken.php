<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class StoreEncryptedPublicQrToken extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('equipo_tokens_publicos', [
            'token_cifrado' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'token_hash',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('equipo_tokens_publicos', 'token_cifrado');
    }
}
