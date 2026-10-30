<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddRouteCodeAndTonnageToOperationalTripLegs extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('codigo_origen_destino', 'viaje_operativo_tramos')) {
            $this->forge->addColumn('viaje_operativo_tramos', [
                'codigo_origen_destino' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'orden'],
            ]);
        }
        if (! $this->db->fieldExists('toneladas', 'viaje_operativo_tramos')) {
            $this->forge->addColumn('viaje_operativo_tramos', [
                'toneladas' => ['type' => 'DECIMAL', 'constraint' => '14,2', 'unsigned' => true, 'null' => true, 'after' => 'km'],
            ]);
        }
        if (! $this->db->fieldExists('localidad_origen_id', 'viaje_operativo_tramos')) {
            $this->forge->addColumn('viaje_operativo_tramos', [
                'localidad_origen_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'codigo_origen_destino'],
            ]);
        }
        if (! $this->db->fieldExists('localidad_destino_id', 'viaje_operativo_tramos')) {
            $this->forge->addColumn('viaje_operativo_tramos', [
                'localidad_destino_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'localidad_origen_id'],
            ]);
        }
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('viaje_operativo_tramos') . ' ADD CONSTRAINT fk_viaje_operativo_tramos_localidad_origen FOREIGN KEY (localidad_origen_id) REFERENCES ' . $this->db->prefixTable('localidades') . ' (id) ON UPDATE RESTRICT ON DELETE RESTRICT');
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('viaje_operativo_tramos') . ' ADD CONSTRAINT fk_viaje_operativo_tramos_localidad_destino FOREIGN KEY (localidad_destino_id) REFERENCES ' . $this->db->prefixTable('localidades') . ' (id) ON UPDATE RESTRICT ON DELETE RESTRICT');
        }
    }

    public function down(): void
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('viaje_operativo_tramos') . ' DROP FOREIGN KEY fk_viaje_operativo_tramos_localidad_destino');
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('viaje_operativo_tramos') . ' DROP FOREIGN KEY fk_viaje_operativo_tramos_localidad_origen');
        }
        if ($this->db->fieldExists('localidad_destino_id', 'viaje_operativo_tramos')) {
            $this->forge->dropColumn('viaje_operativo_tramos', 'localidad_destino_id');
        }
        if ($this->db->fieldExists('localidad_origen_id', 'viaje_operativo_tramos')) {
            $this->forge->dropColumn('viaje_operativo_tramos', 'localidad_origen_id');
        }
        if ($this->db->fieldExists('toneladas', 'viaje_operativo_tramos')) {
            $this->forge->dropColumn('viaje_operativo_tramos', 'toneladas');
        }
        if ($this->db->fieldExists('codigo_origen_destino', 'viaje_operativo_tramos')) {
            $this->forge->dropColumn('viaje_operativo_tramos', 'codigo_origen_destino');
        }
    }
}
