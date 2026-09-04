<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AllowAnonymousEquipmentReadings extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('lecturas_equipo', [
            'usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
    }

    public function down(): void
    {
        // No se revierte automáticamente: podrían existir lecturas QR anónimas con usuario_id NULL.
    }
}
