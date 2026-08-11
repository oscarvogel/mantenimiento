<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreatePreventiveLibrary extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('categoria', 'tipos_servicio')) {
            $this->forge->addColumn('tipos_servicio', [
                'categoria' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true, 'after' => 'descripcion'],
            ]);
        }

        if (! $this->db->tableExists('tipo_servicio_materiales')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
                'tipo_servicio_id' => ['type' => 'INT', 'constraint' => 11],
                'codigo' => ['type' => 'VARCHAR', 'constraint' => 80],
                'descripcion' => ['type' => 'VARCHAR', 'constraint' => 255],
                'tipo_item' => ['type' => 'VARCHAR', 'constraint' => 20],
                'unidad' => ['type' => 'VARCHAR', 'constraint' => 20],
                'cantidad_referencia' => ['type' => 'DECIMAL', 'constraint' => '12,3', 'null' => true],
                'cantidad_variable' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'codigo_repuesto_catalogo' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'obligatorio' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'observaciones' => ['type' => 'TEXT', 'null' => true],
                'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('tipo_servicio_id');
            $this->forge->addUniqueKey(['tipo_servicio_id', 'codigo'], 'uq_servicio_material_codigo');
            $this->forge->createTable('tipo_servicio_materiales', true);
        }

        if (! $this->db->tableExists('plantillas_mantenimiento')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
                'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'codigo' => ['type' => 'VARCHAR', 'constraint' => 80],
                'nombre' => ['type' => 'VARCHAR', 'constraint' => 150],
                'ambito' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'EMPRESA'],
                'tipo_equipo_id' => ['type' => 'INT', 'constraint' => 11],
                'marca' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'modelo' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'descripcion' => ['type' => 'TEXT', 'null' => true],
                'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'created_by' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'updated_by' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
                'deleted_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('empresa_id');
            $this->forge->addKey('tipo_equipo_id');
            $this->forge->addUniqueKey(['empresa_id', 'codigo'], 'uq_plantilla_empresa_codigo');
            $this->forge->createTable('plantillas_mantenimiento', true);
        }

        if (! $this->db->tableExists('plantilla_mantenimiento_items')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
                'plantilla_id' => ['type' => 'INT', 'constraint' => 11],
                'tipo_servicio_id' => ['type' => 'INT', 'constraint' => 11],
                'intervalo_km' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'intervalo_horas' => ['type' => 'DECIMAL', 'constraint' => '12,1', 'null' => true],
                'intervalo_dias' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'anticipacion_km' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'anticipacion_horas' => ['type' => 'DECIMAL', 'constraint' => '12,1', 'null' => true],
                'anticipacion_dias' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'prioridad' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'MEDIA'],
                'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'observaciones' => ['type' => 'TEXT', 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('plantilla_id');
            $this->forge->addKey('tipo_servicio_id');
            $this->forge->addUniqueKey(['plantilla_id', 'tipo_servicio_id'], 'uq_plantilla_servicio');
            $this->forge->createTable('plantilla_mantenimiento_items', true);
        }

        $columns = [];
        if (! $this->db->fieldExists('origen_plantilla_id', 'planes_mantenimiento')) {
            $columns['origen_plantilla_id'] = ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'tipo_servicio_id'];
        }
        if (! $this->db->fieldExists('origen_plantilla_item_id', 'planes_mantenimiento')) {
            $columns['origen_plantilla_item_id'] = ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'origen_plantilla_id'];
        }
        if ($columns !== []) {
            $this->forge->addColumn('planes_mantenimiento', $columns);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('plantilla_mantenimiento_items')) $this->forge->dropTable('plantilla_mantenimiento_items', true);
        if ($this->db->tableExists('plantillas_mantenimiento')) $this->forge->dropTable('plantillas_mantenimiento', true);
        if ($this->db->tableExists('tipo_servicio_materiales')) $this->forge->dropTable('tipo_servicio_materiales', true);
        if ($this->db->fieldExists('origen_plantilla_item_id', 'planes_mantenimiento')) $this->forge->dropColumn('planes_mantenimiento', 'origen_plantilla_item_id');
        if ($this->db->fieldExists('origen_plantilla_id', 'planes_mantenimiento')) $this->forge->dropColumn('planes_mantenimiento', 'origen_plantilla_id');
        if ($this->db->fieldExists('categoria', 'tipos_servicio')) $this->forge->dropColumn('tipos_servicio', 'categoria');
    }
}
