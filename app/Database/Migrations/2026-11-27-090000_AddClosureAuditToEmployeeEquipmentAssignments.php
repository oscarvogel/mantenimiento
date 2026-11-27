<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Conserva el motivo y las observaciones del cierre de una asignación.
 *
 * La asignación histórica no se edita: `observaciones` sigue siendo la nota
 * original del alta. El motivo de desvinculación queda en columnas propias para
 * que la ficha del equipo y la auditoría puedan mostrarlo sin perder contexto.
 */
final class AddClosureAuditToEmployeeEquipmentAssignments extends Migration
{
    private const TABLE = 'employee_equipment_assignments';

    public function up(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }

        $fields = [];
        if (! $this->db->fieldExists('motivo_cierre', self::TABLE)) {
            $fields['motivo_cierre'] = [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'observaciones',
            ];
        }
        if (! $this->db->fieldExists('observaciones_cierre', self::TABLE)) {
            $fields['observaciones_cierre'] = [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'motivo_cierre',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn(self::TABLE, $fields);
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }

        foreach (['observaciones_cierre', 'motivo_cierre'] as $field) {
            if ($this->db->fieldExists($field, self::TABLE)) {
                $this->forge->dropColumn(self::TABLE, $field);
            }
        }
    }
}
