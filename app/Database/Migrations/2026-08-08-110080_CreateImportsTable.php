<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateImportsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tipo' => ['type' => 'VARCHAR', 'constraint' => 20],
            'archivo_original' => ['type' => 'VARCHAR', 'constraint' => 255],
            'ruta_privada' => ['type' => 'VARCHAR', 'constraint' => 500],
            'mime_type' => ['type' => 'VARCHAR', 'constraint' => 150],
            'sha256' => ['type' => 'CHAR', 'constraint' => 64],
            'origen' => ['type' => 'VARCHAR', 'constraint' => 100],
            'usuario_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'FALLIDO'],
            'fecha' => ['type' => 'DATETIME'],
            'fecha_confirmacion' => ['type' => 'DATETIME', 'null' => true],
            'confirmada_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'fecha_cancelacion' => ['type' => 'DATETIME', 'null' => true],
            'cancelada_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'filas_totales' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'filas_validas' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'filas_error' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'filas_duplicadas' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'filas_importadas' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'resumen' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['empresa_id', 'fecha'], false, false, 'idx_importaciones_scope_fecha');
        $this->forge->addKey(['empresa_id', 'estado'], false, false, 'idx_importaciones_scope_estado');
        $this->forge->addKey('usuario_id');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('confirmada_por', 'usuarios', 'id', 'RESTRICT', 'SET NULL');
        $this->forge->addForeignKey('cancelada_por', 'usuarios', 'id', 'RESTRICT', 'SET NULL');
        $this->forge->createTable('importaciones');
    }

    public function down(): void
    {
        $this->forge->dropTable('importaciones');
    }
}
