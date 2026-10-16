<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class CreateCompanyPresentationProfiles extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'frontend_profile' => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => 'base'],
            'perfil_exclusivo' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('empresa_id', 'uq_empresa_presentacion_empresa');
        $this->forge->addUniqueKey('perfil_exclusivo', 'uq_empresa_presentacion_perfil_exclusivo');
        $this->forge->addKey('frontend_profile', false, false, 'idx_empresa_presentacion_perfil');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_empresa_presentacion_empresa');
        $this->forge->addForeignKey('created_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT', 'fk_empresa_presentacion_created_by');
        $this->forge->addForeignKey('updated_by', 'usuarios', 'id', 'SET NULL', 'RESTRICT', 'fk_empresa_presentacion_updated_by');
        $this->forge->createTable('empresa_configuracion_presentacion');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'perfil_anterior' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'perfil_nuevo' => ['type' => 'VARCHAR', 'constraint' => 64],
            'actor_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['empresa_id', 'created_at'], false, false, 'idx_empresa_presentacion_historial_fecha');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT', 'fk_empresa_presentacion_historial_empresa');
        $this->forge->addForeignKey('actor_user_id', 'usuarios', 'id', 'RESTRICT', 'RESTRICT', 'fk_empresa_presentacion_historial_actor');
        $this->forge->createTable('empresa_presentacion_perfil_historial');
    }

    public function down(): void
    {
        $this->assertTableEmptyBeforeDrop('empresa_presentacion_perfil_historial');
        $this->assertTableEmptyBeforeDrop('empresa_configuracion_presentacion');

        $this->forge->dropTable('empresa_presentacion_perfil_historial', true);
        $this->forge->dropTable('empresa_configuracion_presentacion', true);
    }

    private function assertTableEmptyBeforeDrop(string $table): void
    {
        if ($this->db->tableExists($table) && $this->db->table($table)->countAllResults() > 0) {
            throw new RuntimeException('down() no puede eliminar la tabla `' . $table . '` porque contiene datos de configuración o auditoría.');
        }
    }
}
