<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class PrepareUnifiedMaintenanceServices extends Migration
{
    public function up(): void
    {
        $columns = [];

        if (! $this->db->fieldExists('empresa_id', 'tipos_servicio')) {
            $columns['empresa_id'] = [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'id',
            ];
        }
        if (! $this->db->fieldExists('intervalo_km', 'tipos_servicio')) {
            $columns['intervalo_km'] = [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'null' => true,
                'after' => 'categoria',
            ];
        }
        if (! $this->db->fieldExists('intervalo_horas', 'tipos_servicio')) {
            $columns['intervalo_horas'] = [
                'type' => 'DECIMAL',
                'constraint' => '12,1',
                'unsigned' => true,
                'null' => true,
                'after' => 'intervalo_km',
            ];
        }
        if (! $this->db->fieldExists('intervalo_dias', 'tipos_servicio')) {
            $columns['intervalo_dias'] = [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'null' => true,
                'after' => 'intervalo_horas',
            ];
        }
        if (! $this->db->fieldExists('anticipacion_km', 'tipos_servicio')) {
            $columns['anticipacion_km'] = [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'null' => true,
                'after' => 'intervalo_dias',
            ];
        }
        if (! $this->db->fieldExists('anticipacion_horas', 'tipos_servicio')) {
            $columns['anticipacion_horas'] = [
                'type' => 'DECIMAL',
                'constraint' => '12,1',
                'unsigned' => true,
                'null' => true,
                'after' => 'anticipacion_km',
            ];
        }
        if (! $this->db->fieldExists('anticipacion_dias', 'tipos_servicio')) {
            $columns['anticipacion_dias'] = [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'null' => true,
                'after' => 'anticipacion_horas',
            ];
        }
        if (! $this->db->fieldExists('prioridad', 'tipos_servicio')) {
            $columns['prioridad'] = [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'MEDIA',
                'after' => 'anticipacion_dias',
            ];
        }
        if (! $this->db->fieldExists('created_by', 'tipos_servicio')) {
            $columns['created_by'] = [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'activo',
            ];
        }
        if (! $this->db->fieldExists('updated_by', 'tipos_servicio')) {
            $columns['updated_by'] = [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'created_by',
            ];
        }

        if ($columns !== []) {
            $this->forge->addColumn('tipos_servicio', $columns);
        }

        // Esta migración es deliberadamente no destructiva. `empresa_id` queda nullable
        // hasta el cutover porque los servicios legacy pueden haber sido compartidos por
        // varias empresas y no corresponde inventar pertenencia. El reset de datos de
        // prueba y la unicidad empresa+codigo se aplicarán cuando #74 migre todos los
        // escritores/lectores al catálogo único.
    }

    public function down(): void
    {
        $columns = [
            'updated_by',
            'created_by',
            'prioridad',
            'anticipacion_dias',
            'anticipacion_horas',
            'anticipacion_km',
            'intervalo_dias',
            'intervalo_horas',
            'intervalo_km',
            'empresa_id',
        ];

        foreach ($columns as $column) {
            if ($this->db->fieldExists($column, 'tipos_servicio')) {
                $this->forge->dropColumn('tipos_servicio', $column);
            }
        }
    }
}
