<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateExpirationRenovationHistoryTables extends Migration
{
    public function up(): void
    {
        // Adjuntos asociados a renovaciones de vencimientos. Conviven con los
        // registros del vencimiento porque la renovacion puede aportar una
        // evidencia (PDF/foto) que el vencimiento vigente no conserva.
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'vencimiento_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'nombre_original' => ['type' => 'VARCHAR', 'constraint' => 255],
            'nombre_almacenado' => ['type' => 'VARCHAR', 'constraint' => 64],
            'ruta_privada' => ['type' => 'VARCHAR', 'constraint' => 255],
            'mime_type' => ['type' => 'VARCHAR', 'constraint' => 100],
            'tamanio' => ['type' => 'BIGINT', 'unsigned' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('ruta_privada', 'uq_vencimiento_evidencia_ruta');
        $this->forge->addKey(
            ['empresa_id', 'vencimiento_id', 'deleted_at'],
            false,
            false,
            'idx_vencimiento_evidencia_listado',
        );
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_vencimiento_evidencia_empresa');
        $this->forge->addForeignKey(
            ['empresa_id', 'vencimiento_id'],
            'vencimientos',
            ['empresa_id', 'id'],
            'RESTRICT',
            'RESTRICT',
            'fk_vencimiento_evidencia_vencimiento',
        );
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'RESTRICT', 'RESTRICT', 'fk_vencimiento_evidencia_usuario');
        $this->forge->createTable('vencimiento_evidencias', true);

        // Historial de renovaciones: conserva la fecha anterior y la nueva, el
        // usuario y la fecha/hora del cambio. La fila del vencimiento vigente
        // sigue siendo el unico registro "vivo" para listados y contadores;
        // esta tabla es append-only por diseno.
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'vencimiento_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'fecha_anterior' => ['type' => 'DATE'],
            'fecha_nueva' => ['type' => 'DATE'],
            'fecha_renovacion' => ['type' => 'DATETIME'],
            'usuario_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'observaciones' => ['type' => 'TEXT', 'null' => true],
            'evidencia_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(
            ['empresa_id', 'vencimiento_id', 'fecha_renovacion'],
            false,
            false,
            'idx_vencimiento_historial_listado',
        );
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_vencimiento_historial_empresa');
        $this->forge->addForeignKey(
            ['empresa_id', 'vencimiento_id'],
            'vencimientos',
            ['empresa_id', 'id'],
            'RESTRICT',
            'RESTRICT',
            'fk_vencimiento_historial_vencimiento',
        );
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'SET NULL', 'RESTRICT', 'fk_vencimiento_historial_usuario');
        $this->forge->addForeignKey(
            ['empresa_id', 'evidencia_id'],
            'vencimiento_evidencias',
            ['empresa_id', 'id'],
            'SET NULL',
            'RESTRICT',
            'fk_vencimiento_historial_evidencia',
        );
        $this->forge->createTable('vencimiento_renovaciones', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('vencimiento_renovaciones', true);
        $this->forge->dropTable('vencimiento_evidencias', true);
    }
}
