<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateImportRowsAndErrorsTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'importacion_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'numero_fila' => ['type' => 'INT', 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20],
            'datos_originales' => ['type' => 'TEXT'],
            'datos_normalizados' => ['type' => 'TEXT'],
            'resultado' => ['type' => 'TEXT', 'null' => true],
            'destino_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'procesada_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['importacion_id', 'numero_fila'], 'uq_importacion_fila');
        $this->forge->addKey(['importacion_id', 'estado'], false, false, 'idx_importacion_filas_estado');
        $this->forge->addKey('sucursal_id');
        $this->forge->addForeignKey('importacion_id', 'importaciones', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('sucursal_id', 'sucursales', 'id', 'RESTRICT', 'SET NULL');
        $this->forge->createTable('importacion_filas');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'importacion_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'importacion_fila_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'numero_fila' => ['type' => 'INT', 'unsigned' => true],
            'campo' => ['type' => 'VARCHAR', 'constraint' => 100],
            'valor' => ['type' => 'TEXT', 'null' => true],
            'mensaje' => ['type' => 'VARCHAR', 'constraint' => 500],
            'severidad' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'ERROR'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['importacion_id', 'numero_fila'], false, false, 'idx_importacion_errores_fila');
        $this->forge->addForeignKey('importacion_id', 'importaciones', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('importacion_fila_id', 'importacion_filas', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('importacion_errores');
    }

    public function down(): void
    {
        $this->forge->dropTable('importacion_errores');
        $this->forge->dropTable('importacion_filas');
    }
}
