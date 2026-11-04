<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Conceptos de gasto y haber por empresa.
 *
 * Cada concepto declara a qué registro aplica (gasto, haber o ambos), cómo se
 * mide (día, unidad o importe fijo) y cuánto vale. Las cargas del viaje guardan
 * un snapshot del concepto, la cantidad y el importe unitario, de modo que un
 * cambio posterior de tarifa no altera viajes ya registrados.
 */
final class CreateTripConcepts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 120],
            'categoria' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'AMBOS'],
            'unidad' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'UNIDAD'],
            'importe_unitario' => ['type' => 'DECIMAL', 'constraint' => '18,4', 'default' => 0],
            'moneda' => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'ARS'],
            'permite_editar_importe' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'nombre'], 'uq_viajes_conceptos_nombre');
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_viajes_conceptos_empresa_id');
        $this->forge->addKey(['empresa_id', 'categoria', 'activo'], false, false, 'idx_viajes_conceptos_categoria');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('viajes_conceptos', true);

        $this->seedInitialConcepts();
    }

    public function down(): void
    {
        $this->forge->dropTable('viajes_conceptos', true);
    }

    private function seedInitialConcepts(): void
    {
        if (! $this->db->tableExists('empresas')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $concepts = [
            [
                'nombre' => 'Estadía Sider',
                'categoria' => 'HABER',
                'unidad' => 'DIA',
                'importe_unitario' => 50000,
                'moneda' => 'ARS',
                'permite_editar_importe' => 1,
            ],
            [
                'nombre' => 'Estadía Cámara de frío',
                'categoria' => 'HABER',
                'unidad' => 'DIA',
                'importe_unitario' => 80000,
                'moneda' => 'ARS',
                'permite_editar_importe' => 1,
            ],
        ];

        foreach ($this->db->table('empresas')->select('id')->get()->getResultArray() as $company) {
            foreach ($concepts as $concept) {
                $exists = $this->db->table('viajes_conceptos')
                    ->where('empresa_id', (int) $company['id'])
                    ->where('nombre', $concept['nombre'])
                    ->countAllResults();
                if ($exists > 0) {
                    continue;
                }

                $this->db->table('viajes_conceptos')->insert($concept + [
                    'empresa_id' => (int) $company['id'],
                    'activo' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
