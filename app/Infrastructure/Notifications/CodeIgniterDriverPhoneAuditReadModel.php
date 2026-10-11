<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\DriverPhoneAuditAssignment;
use App\Application\Notifications\Port\DriverPhoneAuditReadModel;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterDriverPhoneAuditReadModel implements DriverPhoneAuditReadModel
{
    public function __construct(private BaseConnection $db)
    {
    }

    public function currentAssignments(): array
    {
        $rows = $this->db->table('employee_equipment_assignments a')
            ->select('a.empresa_id AS company_id, a.empleado_id AS employee_id')
            ->select('emp.nombre AS first_name, emp.apellido AS last_name, emp.telefono AS phone')
            ->select('e.id AS equipment_id, e.codigo AS equipment_code, e.patente AS plate')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id', 'inner')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id', 'inner')
            ->join('empresas co', 'co.id = a.empresa_id', 'inner')
            ->where('a.rol', 'CHOFER')
            ->where('a.fecha_hasta', null)
            ->where('emp.activo', 1)
            ->where('emp.deleted_at', null)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->where('co.estado', 1)
            ->where('co.deleted_at', null)
            ->where('co.notificaciones_whatsapp_habilitadas', 1)
            ->orderBy('a.id', 'DESC')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): DriverPhoneAuditAssignment => new DriverPhoneAuditAssignment(
            (int) $row['company_id'],
            (int) $row['employee_id'],
            (string) ($row['first_name'] ?? ''),
            (string) ($row['last_name'] ?? ''),
            $row['phone'] === null ? null : (string) $row['phone'],
            (int) $row['equipment_id'],
            (string) ($row['equipment_code'] ?? ''),
            $row['plate'] === null ? null : (string) $row['plate'],
        ), $rows);
    }

    public function responsibleAdminUserIds(int $companyId): array
    {
        $rows = $this->db->table('usuarios u')
            ->select('DISTINCT u.id', false)
            ->join('usuario_roles ur', 'ur.usuario_id = u.id', 'inner')
            ->join('roles r', 'r.id = ur.rol_id', 'inner')
            ->where('u.empresa_id', $companyId)
            ->where('u.activo', 1)
            ->where('u.deleted_at', null)
            ->where('r.nombre', 'Responsable de mantenimiento')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
}
