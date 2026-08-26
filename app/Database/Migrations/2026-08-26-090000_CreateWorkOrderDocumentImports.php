<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateWorkOrderDocumentImports extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sucursal_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'equipo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'original_name' => ['type' => 'VARCHAR', 'constraint' => 255],
            'stored_name' => ['type' => 'VARCHAR', 'constraint' => 80],
            'private_relative_path' => ['type' => 'VARCHAR', 'constraint' => 255],
            'mime_type' => ['type' => 'VARCHAR', 'constraint' => 100],
            'size_bytes' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sha256' => ['type' => 'CHAR', 'constraint' => 64],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 100],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'SUBIDO'],
            'analysis_json' => ['type' => 'LONGTEXT', 'null' => true],
            'proposal_json' => ['type' => 'LONGTEXT', 'null' => true],
            'analysis_error' => ['type' => 'TEXT', 'null' => true],
            'confirmed_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['empresa_id', 'sucursal_id', 'status']);
        $this->forge->addUniqueKey(['empresa_id', 'idempotency_key'], 'uq_ot_doc_import_idempotency');
        $this->forge->addKey(['empresa_id', 'sha256']);
        $this->forge->createTable('ot_document_imports', true);

        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'import_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'orden_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'kind' => ['type' => 'VARCHAR', 'constraint' => 20],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['empresa_id', 'import_id', 'orden_id'], 'uq_ot_doc_import_order');
        $this->forge->addKey(['empresa_id', 'orden_id']);
        $this->forge->createTable('ot_document_import_orders', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('ot_document_import_orders', true);
        $this->forge->dropTable('ot_document_imports', true);
    }
}
