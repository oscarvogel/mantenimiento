<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateCustomersAndImports extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 255],
            'identificacion_fiscal' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'identificacion_fiscal_pais' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'direccion' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'provincia' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'localidad' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'localidad_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'contacto' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'telefono' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'email' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        // Exact source references remain distinct even under a case-insensitive DB default.
        $this->forge->addField('origen VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        $this->forge->addField('codigo_origen VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['empresa_id', 'origen', 'codigo_origen'], 'uq_clientes_empresa_origen_codigo');
        $this->forge->addUniqueKey(['empresa_id', 'id'], 'uq_clientes_empresa_id');
        $this->forge->addKey(['empresa_id', 'activo', 'nombre'], false, false, 'idx_clientes_empresa_activo_nombre');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_clientes_empresa');
        $this->forge->addForeignKey('localidad_id', 'localidades', 'id', 'RESTRICT', 'RESTRICT', 'fk_clientes_localidad');
        $this->forge->createTable('clientes');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'usuario_id' => ['type' => 'INT', 'unsigned' => true],
            'archivo_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'estado' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'PENDIENTE'],
            'filas' => ['type' => 'LONGTEXT'],
            'resultado' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['empresa_id', 'usuario_id', 'estado'], false, false, 'idx_cliente_importaciones_scope');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_cliente_importaciones_empresa');
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'RESTRICT', 'RESTRICT', 'fk_cliente_importaciones_usuario');
        $this->forge->createTable('cliente_importaciones');

        // Append-only confirmation evidence; never store the client register here.
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'usuario_id' => ['type' => 'INT', 'unsigned' => true],
            'importacion_id' => ['type' => 'INT', 'unsigned' => true],
            'resultado' => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('importacion_id', 'uq_cliente_importacion_auditoria_lote');
        $this->forge->addKey(['empresa_id', 'created_at'], false, false, 'idx_cliente_importacion_auditoria_empresa');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_cliente_importacion_auditoria_empresa');
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'RESTRICT', 'RESTRICT', 'fk_cliente_importacion_auditoria_usuario');
        $this->forge->addForeignKey('importacion_id', 'cliente_importaciones', 'id', 'RESTRICT', 'RESTRICT', 'fk_cliente_importacion_auditoria_lote');
        $this->forge->createTable('cliente_importacion_auditoria');
    }

    public function down(): void
    {
        $this->forge->dropTable('cliente_importacion_auditoria', true);
        $this->forge->dropTable('cliente_importaciones', true);
        $this->forge->dropTable('clientes', true);
    }
}
