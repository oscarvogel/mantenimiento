<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateReadingEvidenceReviews extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'evidencia_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'lectura_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'motivo' => ['type' => 'VARCHAR', 'constraint' => 40],
            'observacion' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'revisado_por' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'revisado_at' => ['type' => 'DATETIME'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('evidencia_id', 'uq_evidencia_revision');
        $this->forge->addKey(['empresa_id', 'revisado_at'], false, false, 'idx_evidencia_revision_empresa');
        $this->forge->addKey('lectura_id', false, false, 'idx_evidencia_revision_lectura');
        $this->forge->addForeignKey('empresa_id', 'empresas', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('evidencia_id', 'lecturas_equipo_evidencias', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('lectura_id', 'lecturas_equipo', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('revisado_por', 'usuarios', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->createTable('lecturas_equipo_evidencias_revisiones', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('lecturas_equipo_evidencias_revisiones', true);
    }
}
