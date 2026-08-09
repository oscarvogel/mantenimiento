<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class GeneralizeAccessHistoryActor extends Migration
{
    public function up(): void
    {
        $this->db->query(
            'ALTER TABLE usuario_acceso_historial '
            . 'DROP FOREIGN KEY usuario_acceso_historial_superadmin_usuario_id_foreign, '
            . 'DROP INDEX superadmin_usuario_id, '
            . 'CHANGE COLUMN superadmin_usuario_id actor_usuario_id INT UNSIGNED NOT NULL, '
            . 'ADD INDEX actor_usuario_id (actor_usuario_id), '
            . 'ADD CONSTRAINT usuario_acceso_historial_actor_usuario_id_foreign '
            . 'FOREIGN KEY (actor_usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT ON UPDATE RESTRICT',
        );
    }

    public function down(): void
    {
        $this->db->query(
            'ALTER TABLE usuario_acceso_historial '
            . 'DROP FOREIGN KEY usuario_acceso_historial_actor_usuario_id_foreign, '
            . 'DROP INDEX actor_usuario_id, '
            . 'CHANGE COLUMN actor_usuario_id superadmin_usuario_id INT UNSIGNED NOT NULL, '
            . 'ADD INDEX superadmin_usuario_id (superadmin_usuario_id), '
            . 'ADD CONSTRAINT usuario_acceso_historial_superadmin_usuario_id_foreign '
            . 'FOREIGN KEY (superadmin_usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT ON UPDATE RESTRICT',
        );
    }
}
