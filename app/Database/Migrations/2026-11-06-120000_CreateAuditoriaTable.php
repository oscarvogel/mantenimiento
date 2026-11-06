<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateAuditoriaTable extends Migration
{
    public function up(): void
    {
        $this->ensureSucursalTenantIndex();

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'empresa_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'sucursal_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'actor_usuario_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'actor_empresa_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'alcance' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
            ],
            'accion' => [
                'type'       => 'VARCHAR',
                'constraint' => 60,
            ],
            'entidad' => [
                'type'       => 'VARCHAR',
                'constraint' => 60,
            ],
            'entidad_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'valores_anteriores' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'valores_nuevos' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'motivo' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'user_agent' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'request_id' => [
                'type'       => 'CHAR',
                'constraint' => 36,
                'null'       => true,
            ],
            'fecha' => [
                'type' => 'DATETIME',
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['empresa_id', 'sucursal_id', 'fecha'], false, false, 'idx_auditoria_scope_fecha');
        $this->forge->addKey(['entidad', 'entidad_id'], false, false, 'idx_auditoria_entidad');
        $this->forge->addKey(['actor_usuario_id', 'fecha'], false, false, 'idx_auditoria_actor_fecha');
        $this->forge->addKey(['accion', 'fecha'], false, false, 'idx_auditoria_accion_fecha');
        $this->forge->addKey('request_id', false, false, 'idx_auditoria_request');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_auditoria_empresa');
        $this->forge->addForeignKey('actor_usuario_id', 'usuarios', 'id', 'RESTRICT', 'RESTRICT', 'fk_auditoria_actor_usuario');
        $this->forge->addForeignKey('actor_empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_auditoria_actor_empresa');
        $this->forge->addForeignKey(
            ['empresa_id', 'sucursal_id'],
            'sucursales',
            ['empresa_id', 'id'],
            'RESTRICT',
            'RESTRICT',
            'fk_auditoria_sucursal_tenant',
        );
        $this->forge->createTable('auditoria', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('auditoria', true);
    }

    private function ensureSucursalTenantIndex(): void
    {
        if (! $this->db->tableExists('sucursales')) {
            return;
        }

        foreach ($this->db->getIndexData('sucursales') as $index) {
            $fields = is_array($index) ? ($index['fields'] ?? []) : ($index->fields ?? []);
            if (count($fields) === 2 && in_array('empresa_id', $fields, true) && in_array('id', $fields, true)) {
                return;
            }
        }

        $this->db->query('CREATE UNIQUE INDEX uq_sucursales_empresa_id ON sucursales (empresa_id, id)');
    }
}
