<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Conserva los mensajes recibidos para explicar cómo se normalizó cada bucket. */
final class AddRawWialonMessagesToTelemetryBuckets extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('telematia_buckets', [
            'mensajes_fuente_json' => [
                'type' => 'LONGTEXT',
                'null' => true,
                'after' => 'satelites_fin',
            ],
        ]);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('mensajes_fuente_json', 'telematia_buckets')) {
            $this->forge->dropColumn('telematia_buckets', 'mensajes_fuente_json');
        }
    }
}
