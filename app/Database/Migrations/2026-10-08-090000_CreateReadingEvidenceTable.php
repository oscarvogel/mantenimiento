<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateReadingEvidenceTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'empresa_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'lectura_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'archivo_path' => ['type' => 'VARCHAR', 'constraint' => 255],
            'mime_type' => ['type' => 'VARCHAR', 'constraint' => 50],
            'archivo_bytes' => ['type' => 'BIGINT', 'unsigned' => true],
            'km_detectado_ia' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'confianza_ia' => ['type' => 'DECIMAL', 'constraint' => '5,4', 'null' => true],
            'estado_ia' => ['type' => 'VARCHAR', 'constraint' => 30],
            'km_confirmado' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'metodo_carga' => ['type' => 'VARCHAR', 'constraint' => 30],
            'observacion_ia' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('lectura_id', 'uq_lectura_evidencia_lectura');
        $this->forge->addKey(['empresa_id', 'lectura_id'], false, false, 'idx_lectura_evidencia_scope');
        $this->forge->addForeignKey('lectura_id', 'lecturas_equipo', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('lecturas_equipo_evidencias');
    }

    public function down(): void
    {
        $this->forge->dropTable('lecturas_equipo_evidencias');
    }
}
