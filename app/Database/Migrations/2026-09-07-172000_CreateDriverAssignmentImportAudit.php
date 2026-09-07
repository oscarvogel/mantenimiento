<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateDriverAssignmentImportAudit extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true],
            'usuario_id' => ['type' => 'INT', 'unsigned' => true],
            'archivo_original' => ['type' => 'VARCHAR', 'constraint' => 255],
            'sha256' => ['type' => 'CHAR', 'constraint' => 64],
            'filas_totales' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'empleados_creados' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'asignaciones_actualizadas' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'sin_cambios' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'sin_chofer' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'detalle_json' => ['type' => 'LONGTEXT'],
            'created_at' => ['type' => 'DATETIME'],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['empresa_id', 'created_at'], false, false, 'idx_driver_import_audit_company_date');
        $this->forge->addKey(['empresa_id', 'sha256'], false, false, 'idx_driver_import_audit_company_hash');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->addForeignKey('usuario_id', 'usuarios', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('driver_assignment_import_audit', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('driver_assignment_import_audit', true);
    }
}
