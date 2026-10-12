<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use DomainException;

final class AllowCompanyWideTheoreticalCostScenarios extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('costos_teoricos_escenarios')) {
            return;
        }

        $this->forge->modifyColumn('costos_teoricos_escenarios', [
            'sucursal_tractor_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'sucursal_semi_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'tractor_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'semi_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
    }

    public function down(): void
    {
        if (! $this->db->tableExists('costos_teoricos_escenarios')) {
            return;
        }

        $hasGeneralScenarios = $this->db->table('costos_teoricos_escenarios')->groupStart()
            ->where('tractor_id', null)->orWhere('semi_id', null)
            ->groupEnd()->countAllResults() > 0;
        if ($hasGeneralScenarios) {
            throw new DomainException('No se puede revertir la migración mientras existan escenarios generales guardados.');
        }

        $this->forge->modifyColumn('costos_teoricos_escenarios', [
            'sucursal_tractor_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_semi_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tractor_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'semi_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
        ]);
    }
}
