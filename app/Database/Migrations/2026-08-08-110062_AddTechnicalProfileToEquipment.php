<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddTechnicalProfileToEquipment extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('equipos', [
            'marca_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'tipo_equipo_id'],
            'modelo_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'marca_id'],
            'anio' => ['type' => 'SMALLINT', 'constraint' => 4, 'unsigned' => true, 'null' => true, 'after' => 'patente'],
            'chasis' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'anio'],
            'motor' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'chasis'],
        ]);

        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('ALTER TABLE equipos ADD CONSTRAINT fk_equipos_marca_tenant FOREIGN KEY (empresa_id, marca_id) REFERENCES marcas (empresa_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT');
            $this->db->query('ALTER TABLE equipos ADD CONSTRAINT fk_equipos_modelo_tenant FOREIGN KEY (empresa_id, modelo_id) REFERENCES modelos (empresa_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT');
            $this->db->query('ALTER TABLE equipos ADD INDEX idx_equipos_marca (empresa_id, marca_id), ADD INDEX idx_equipos_modelo (empresa_id, modelo_id), ADD INDEX idx_equipos_chasis (empresa_id, chasis)');
        }
    }

    public function down(): void
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('ALTER TABLE equipos DROP FOREIGN KEY fk_equipos_modelo_tenant, DROP FOREIGN KEY fk_equipos_marca_tenant');
        }
        $this->forge->dropColumn('equipos', ['marca_id', 'modelo_id', 'anio', 'chasis', 'motor']);
    }
}
