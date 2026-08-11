<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class ExpandImportType extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('importaciones', [
            'tipo' => ['name' => 'tipo', 'type' => 'VARCHAR', 'constraint' => 40],
        ]);
    }

    public function down(): void
    {
        // No se reduce a VARCHAR(20): BIBLIOTECA_PREVENTIVA necesita 21 caracteres
        // y achicar la columna podría truncar historial de importaciones.
    }
}
