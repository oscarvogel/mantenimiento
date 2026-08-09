<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * MySQL/MariaDB can express the complete tenant + brand + type compatibility
 * as a composite foreign key. SQLite keeps the same invariant in the use case.
 */
final class EnforceEquipmentModelCompatibility extends Migration
{
    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return;
        }

        $this->db->query('ALTER TABLE modelos ADD UNIQUE INDEX uq_modelos_compatibilidad (empresa_id, id, marca_id, tipo_equipo_id)');
        $this->db->query('ALTER TABLE equipos DROP FOREIGN KEY fk_equipos_modelo_tenant');
        $this->db->query('ALTER TABLE equipos ADD CONSTRAINT fk_equipos_modelo_compatible FOREIGN KEY (empresa_id, modelo_id, marca_id, tipo_equipo_id) REFERENCES modelos (empresa_id, id, marca_id, tipo_equipo_id) ON UPDATE RESTRICT ON DELETE RESTRICT');
    }

    public function down(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return;
        }

        $this->db->query('ALTER TABLE equipos DROP FOREIGN KEY fk_equipos_modelo_compatible');
        $this->db->query('ALTER TABLE equipos ADD CONSTRAINT fk_equipos_modelo_tenant FOREIGN KEY (empresa_id, modelo_id) REFERENCES modelos (empresa_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT');
        $this->db->query('ALTER TABLE modelos DROP INDEX uq_modelos_compatibilidad');
    }
}
