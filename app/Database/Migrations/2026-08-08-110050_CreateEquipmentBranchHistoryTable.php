<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateEquipmentBranchHistoryTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'equipo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_origen_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_destino_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'fecha_movimiento' => ['type' => 'DATETIME'],
            'usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'motivo' => ['type' => 'VARCHAR', 'constraint' => 255],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['empresa_id', 'equipo_id', 'fecha_movimiento'], false, false, 'idx_equipo_sucursal_historial');
        $this->forge->addKey(['empresa_id', 'sucursal_origen_id']);
        $this->forge->addKey(['empresa_id', 'sucursal_destino_id']);
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_esh_empresa');
        $this->forge->addForeignKey(['empresa_id', 'equipo_id'], 'equipos', ['empresa_id', 'id'], 'RESTRICT', 'RESTRICT', 'fk_esh_equipo_tenant');
        $this->forge->addForeignKey('sucursal_origen_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT', 'fk_esh_sucursal_origen');
        $this->forge->addForeignKey('sucursal_destino_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT', 'fk_esh_sucursal_destino');
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'RESTRICT', 'RESTRICT', 'fk_esh_usuario');
        $this->forge->createTable('equipo_sucursal_historial');
    }

    public function down(): void
    {
        $this->forge->dropTable('equipo_sucursal_historial');
    }
}
