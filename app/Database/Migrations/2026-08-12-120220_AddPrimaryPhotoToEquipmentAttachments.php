<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddPrimaryPhotoToEquipmentAttachments extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('equipo_adjuntos', [
            'foto_principal_equipo_id' => [
                'type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true,
                'after' => 'equipo_id',
            ],
            'miniatura_ruta_privada' => [
                'type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'ruta_privada',
            ],
            'miniatura_mime_type' => [
                'type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'miniatura_ruta_privada',
            ],
            'miniatura_tamanio' => [
                'type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => 'miniatura_mime_type',
            ],
        ]);
        $this->forge->addUniqueKey(
            ['empresa_id', 'foto_principal_equipo_id'],
            'uq_equipo_foto_principal_activa',
        );
        $this->forge->processIndexes('equipo_adjuntos');
        $this->db->query(
            'ALTER TABLE equipo_adjuntos ADD CONSTRAINT fk_equipo_foto_principal_tenant '
            . 'FOREIGN KEY (empresa_id, foto_principal_equipo_id) REFERENCES equipos (empresa_id, id) '
            . 'ON DELETE RESTRICT ON UPDATE RESTRICT',
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE equipo_adjuntos DROP FOREIGN KEY fk_equipo_foto_principal_tenant');
        $this->forge->dropKey('equipo_adjuntos', 'uq_equipo_foto_principal_activa');
        $this->forge->dropColumn('equipo_adjuntos', [
            'foto_principal_equipo_id', 'miniatura_ruta_privada', 'miniatura_mime_type', 'miniatura_tamanio',
        ]);
    }
}
