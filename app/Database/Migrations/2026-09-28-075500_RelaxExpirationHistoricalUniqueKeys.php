<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class RelaxExpirationHistoricalUniqueKeys extends Migration
{
    public function up(): void
    {
        // Las claves originales también bloquean versiones históricas/inactivas.
        // La unicidad funcional de la versión activa se valida en la aplicación.
        $this->db->query('ALTER TABLE vencimientos DROP INDEX uq_expiration_equipment_type_date');
        $this->db->query('ALTER TABLE vencimientos DROP INDEX uq_expiration_employee_type_date');

        $this->db->query('CREATE INDEX idx_expiration_equipment_type_date ON vencimientos (empresa_id, equipo_id, tipo_vencimiento_id, fecha_vencimiento, activo)');
        $this->db->query('CREATE INDEX idx_expiration_employee_type_date ON vencimientos (empresa_id, empleado_id, tipo_vencimiento_id, fecha_vencimiento, activo)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX idx_expiration_equipment_type_date ON vencimientos');
        $this->db->query('DROP INDEX idx_expiration_employee_type_date ON vencimientos');

        $this->db->query('ALTER TABLE vencimientos ADD UNIQUE KEY uq_expiration_equipment_type_date (empresa_id, equipo_id, tipo_vencimiento_id, fecha_vencimiento)');
        $this->db->query('ALTER TABLE vencimientos ADD UNIQUE KEY uq_expiration_employee_type_date (empresa_id, empleado_id, tipo_vencimiento_id, fecha_vencimiento)');
    }
}
