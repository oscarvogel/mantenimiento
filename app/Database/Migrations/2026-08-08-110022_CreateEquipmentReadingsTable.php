<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateEquipmentReadingsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'equipo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'fecha_lectura' => ['type' => 'DATETIME'],
            'kilometraje' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'horometro' => [
                'type'       => 'DECIMAL',
                'constraint' => '12,1',
                'unsigned'   => true,
                'null'       => true,
            ],
            'origen' => ['type' => 'VARCHAR', 'constraint' => 30],
            'referencia_origen' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'motivo_correccion' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'lectura_corregida_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'observaciones' => ['type' => 'TEXT', 'null' => true],
            'anulada' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'anulada_at' => ['type' => 'DATETIME', 'null' => true],
            'anulada_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'motivo_anulacion' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['empresa_id', 'sucursal_id', 'equipo_id', 'anulada', 'fecha_lectura'], false, false, 'idx_lecturas_scope');
        $this->forge->addKey(['empresa_id', 'equipo_id', 'fecha_lectura'], false, false, 'idx_lecturas_equipo_fecha');
        $this->forge->addKey('usuario_id');
        $this->forge->addKey('lectura_corregida_id');
        $this->forge->addForeignKey(['empresa_id', 'equipo_id'], 'equipos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_lecturas_equipo_scope');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('lectura_corregida_id', 'lecturas_equipo', 'id', 'RESTRICT', 'SET NULL');
        $this->forge->addForeignKey('anulada_por', 'usuarios', 'id', 'RESTRICT', 'SET NULL');
        $this->forge->createTable('lecturas_equipo');
    }

    public function down(): void
    {
        $this->forge->dropTable('lecturas_equipo');
    }
}
