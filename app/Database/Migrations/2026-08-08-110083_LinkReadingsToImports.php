<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class LinkReadingsToImports extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('lecturas_equipo', [
            'referencia_importacion_id' => [
                'type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => 'referencia_origen',
            ],
        ]);
        $this->forge->addKey('referencia_importacion_id', false, false, 'idx_lecturas_importacion');
        $this->forge->addForeignKey('referencia_importacion_id', 'importaciones', 'id', 'RESTRICT', 'SET NULL', 'fk_lecturas_importacion');
        $this->forge->processIndexes('lecturas_equipo');
    }

    public function down(): void
    {
        $this->forge->dropForeignKey('lecturas_equipo', 'fk_lecturas_importacion');
        $this->forge->dropKey('lecturas_equipo', 'idx_lecturas_importacion');
        $this->forge->dropColumn('lecturas_equipo', 'referencia_importacion_id');
    }
}
