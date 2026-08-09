<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateEquipmentAttachmentsTable extends Migration
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
            'sucursal_snapshot_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tipo' => ['type' => 'VARCHAR', 'constraint' => 50],
            'nombre_original' => ['type' => 'VARCHAR', 'constraint' => 255],
            'nombre_almacenado' => ['type' => 'VARCHAR', 'constraint' => 64],
            'ruta_privada' => ['type' => 'VARCHAR', 'constraint' => 255],
            'mime_type' => ['type' => 'VARCHAR', 'constraint' => 100],
            'tamanio' => ['type' => 'BIGINT', 'unsigned' => true],
            'descripcion' => ['type' => 'TEXT', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
            'retirado_at' => ['type' => 'DATETIME', 'null' => true],
            'retirado_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'motivo_retiro' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('ruta_privada', 'uq_equipo_adjuntos_ruta_privada');
        $this->forge->addKey(
            ['empresa_id', 'equipo_id', 'retirado_at', 'created_at'],
            false,
            false,
            'idx_equipo_adjuntos_listado',
        );
        $this->forge->addKey(['empresa_id', 'sucursal_snapshot_id'], false, false, 'idx_equipo_adjuntos_snapshot');
        $this->forge->addForeignKey(
            ['empresa_id', 'equipo_id'],
            'equipos',
            ['empresa_id', 'id'],
            'RESTRICT',
            'RESTRICT',
            'fk_equipo_adjuntos_equipo_tenant',
        );
        $this->forge->addForeignKey('sucursal_snapshot_id', 'sucursales', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('retirado_by', 'usuarios', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('equipo_adjuntos');
    }

    public function down(): void
    {
        $this->forge->dropTable('equipo_adjuntos');
    }
}
