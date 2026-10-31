<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class AllowSameNameLocalities extends Migration
{
    public function up(): void
    {
        $this->forge->dropKey('localidades', 'uq_localidades_provincia_nombre', false);
        $this->forge->addKey(['provincia_id', 'nombre'], false, false, 'idx_localidades_provincia_nombre');
        $this->forge->processIndexes('localidades');
    }

    public function down(): void
    {
        $table = $this->db->protectIdentifiers('localidades', true);
        $duplicates = $this->db->query(
            'SELECT provincia_id, nombre FROM ' . $table
                . ' GROUP BY provincia_id, nombre HAVING COUNT(*) > 1 LIMIT 1',
        )->getRowArray();
        if ($duplicates !== null) {
            throw new RuntimeException('down() no puede restaurar nombres únicos mientras existan localidades homónimas en una provincia.');
        }

        $this->forge->dropKey('localidades', 'idx_localidades_provincia_nombre', false);
        $this->forge->addUniqueKey(['provincia_id', 'nombre'], 'uq_localidades_provincia_nombre');
        $this->forge->processIndexes('localidades');
    }
}
