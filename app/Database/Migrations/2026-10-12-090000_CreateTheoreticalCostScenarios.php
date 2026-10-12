<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateTheoreticalCostScenarios extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_tractor_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_semi_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tractor_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'semi_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 120],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_cte_empresa_escenario');
        $this->forge->addKey(['empresa_id', 'sucursal_tractor_id', 'created_at'], false, false, 'idx_cte_scope');
        $this->forge->addKey(['empresa_id', 'tractor_id', 'semi_id'], false, false, 'idx_cte_equipos');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_cte_empresa');
        $this->forge->addForeignKey(['empresa_id', 'tractor_id'], 'equipos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_cte_tractor');
        $this->forge->addForeignKey(['empresa_id', 'semi_id'], 'equipos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_cte_semi');
        $this->forge->addForeignKey('sucursal_tractor_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT', 'fk_cte_tractor_branch');
        $this->forge->addForeignKey('sucursal_semi_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT', 'fk_cte_semi_branch');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'RESTRICT', 'RESTRICT', 'fk_cte_created_by');
        $this->forge->createTable('costos_teoricos_escenarios');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'escenario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'numero_version' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'cotizacion_ars_usd' => ['type' => 'DECIMAL', 'constraint' => '16,6', 'null' => true],
            'cotizacion_fecha' => ['type' => 'DATE', 'null' => true],
            'cotizacion_fuente' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'km_mensuales' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => true],
            'tractor_valor_usd' => ['type' => 'DECIMAL', 'constraint' => '16,2', 'null' => true],
            'tractor_residual_usd' => ['type' => 'DECIMAL', 'constraint' => '16,2', 'null' => true],
            'tractor_vida_anios' => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'semi_valor_usd' => ['type' => 'DECIMAL', 'constraint' => '16,2', 'null' => true],
            'semi_residual_usd' => ['type' => 'DECIMAL', 'constraint' => '16,2', 'null' => true],
            'semi_vida_anios' => ['type' => 'DECIMAL', 'constraint' => '8,2', 'null' => true],
            'combustible_litros_100km' => ['type' => 'DECIMAL', 'constraint' => '10,3', 'null' => true],
            'combustible_precio' => ['type' => 'DECIMAL', 'constraint' => '16,4', 'null' => true],
            'combustible_moneda' => ['type' => 'CHAR', 'constraint' => 3, 'null' => true],
            'combustible_fecha' => ['type' => 'DATE', 'null' => true],
            'neumaticos_costo_juego' => ['type' => 'DECIMAL', 'constraint' => '16,4', 'null' => true],
            'neumaticos_recapados' => ['type' => 'DECIMAL', 'constraint' => '16,4', 'null' => true],
            'neumaticos_vida_km' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => true],
            'neumaticos_moneda' => ['type' => 'CHAR', 'constraint' => 3, 'null' => true],
            'mantenimiento_pct' => ['type' => 'DECIMAL', 'constraint' => '7,4', 'null' => true],
            'recargo_pct' => ['type' => 'DECIMAL', 'constraint' => '7,4', 'null' => true],
            'viaje_km_facturables' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => true],
            'viaje_km_no_facturados' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'null' => true],
            'viaje_peajes' => ['type' => 'DECIMAL', 'constraint' => '16,4', 'null' => true],
            'viaje_peajes_moneda' => ['type' => 'CHAR', 'constraint' => 3, 'null' => true],
            'viaje_peajes_ars' => ['type' => 'DECIMAL', 'constraint' => '16,2', 'null' => true],
            'cobertura' => ['type' => 'VARCHAR', 'constraint' => 20],
            'costo_ars_km' => ['type' => 'DECIMAL', 'constraint' => '16,6', 'null' => true],
            'costo_usd_km' => ['type' => 'DECIMAL', 'constraint' => '16,6', 'null' => true],
            'tarifa_ars_km' => ['type' => 'DECIMAL', 'constraint' => '16,6', 'null' => true],
            'tarifa_usd_km' => ['type' => 'DECIMAL', 'constraint' => '16,6', 'null' => true],
            'viaje_costo_ars' => ['type' => 'DECIMAL', 'constraint' => '16,2', 'null' => true],
            'viaje_tarifa_ars_km' => ['type' => 'DECIMAL', 'constraint' => '16,6', 'null' => true],
            'viaje_tarifa_usd_km' => ['type' => 'DECIMAL', 'constraint' => '16,6', 'null' => true],
            'faltantes_json' => ['type' => 'TEXT'],
            'creado_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'guardado_en' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['escenario_id', 'numero_version'], 'uq_ctev_escenario_version');
        $this->forge->addKey(['guardado_en', 'cobertura'], false, false, 'idx_ctev_guardado');
        $this->forge->addForeignKey('escenario_id', 'costos_teoricos_escenarios', 'id', 'RESTRICT', 'RESTRICT', 'fk_ctev_escenario');
        $this->forge->addForeignKey('creado_por', 'usuarios', 'id', 'RESTRICT', 'RESTRICT', 'fk_ctev_created_by');
        $this->forge->createTable('costos_teoricos_versiones');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'version_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'codigo' => ['type' => 'VARCHAR', 'constraint' => 80],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 120],
            'tipo' => ['type' => 'VARCHAR', 'constraint' => 20],
            'importe' => ['type' => 'DECIMAL', 'constraint' => '16,4', 'null' => true],
            'moneda' => ['type' => 'CHAR', 'constraint' => 3, 'null' => true],
            'unidad' => ['type' => 'VARCHAR', 'constraint' => 20],
            'fecha_referencia' => ['type' => 'DATE', 'null' => true],
            'fuente' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'ars_km' => ['type' => 'DECIMAL', 'constraint' => '16,6', 'null' => true],
            'usd_km' => ['type' => 'DECIMAL', 'constraint' => '16,6', 'null' => true],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['version_id', 'tipo'], false, false, 'idx_ctevr_version');
        $this->forge->addForeignKey('version_id', 'costos_teoricos_versiones', 'id', 'RESTRICT', 'RESTRICT', 'fk_ctevr_version');
        $this->forge->createTable('costos_teoricos_rubros');

        $typeExists = $this->db->table('tipos_equipo')->groupStart()
            ->like('nombre', 'semirremolque')->orLike('nombre', 'acoplado')->orLike('nombre', 'semi')
            ->groupEnd()->countAllResults();
        if (! $typeExists) {
            $now = date('Y-m-d H:i:s');
            $this->db->table('tipos_equipo')->insert([
                'nombre' => 'Semirremolque', 'controla_km' => 1, 'controla_horas' => 0,
                'activo' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            $existing = $this->db->table('tipos_equipo')->select('id')->groupStart()
                ->like('nombre', 'semirremolque')->orLike('nombre', 'acoplado')->orLike('nombre', 'semi')
                ->groupEnd()->orderBy('id', 'ASC')->get()->getRowArray();
            if ($existing !== null) {
                $this->db->table('tipos_equipo')->where('id', (int) $existing['id'])->update([
                    'controla_km' => 1, 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('costos_teoricos_rubros', true);
        $this->forge->dropTable('costos_teoricos_versiones', true);
        $this->forge->dropTable('costos_teoricos_escenarios', true);
    }
}
