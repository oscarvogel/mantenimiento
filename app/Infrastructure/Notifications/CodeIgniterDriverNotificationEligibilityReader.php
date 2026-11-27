<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\DriverNotificationEligibilityReader;
use App\Domain\Notifications\DriverAssignmentFacts;
use App\Domain\Notifications\DriverNotificationEligibility;
use App\Domain\Notifications\DriverNotificationEligibilityPolicy;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseBuilder;

/**
 * Adaptador del predicado único de elegibilidad.
 *
 * Carga los hechos con una sola consulta y delega la decisión en la política
 * de dominio. No replica criterios en SQL: la consulta no filtra por estado
 * del empleado, del equipo, del tipo ni de la empresa; solo selecciona la fila
 * de asignación que corresponde evaluar.
 */
final readonly class CodeIgniterDriverNotificationEligibilityReader implements DriverNotificationEligibilityReader
{
    private DriverNotificationEligibilityPolicy $policy;

    public function __construct(
        private BaseConnection $database,
        ?DriverNotificationEligibilityPolicy $policy = null,
    ) {
        $this->policy = $policy ?? new DriverNotificationEligibilityPolicy();
    }

    public function evaluate(
        int $companyId,
        int $equipmentId,
        int $employeeId,
        string $today,
    ): DriverNotificationEligibility {
        if ($companyId <= 0 || $equipmentId <= 0 || $employeeId <= 0) {
            return $this->policy->evaluate(null, $today);
        }

        $row = $this->assignmentQuery($companyId)
            ->where('a.equipo_id', $equipmentId)
            ->where('a.empleado_id', $employeeId)
            ->orderBy('a.fecha_desde', 'DESC')
            ->orderBy('a.id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        return $this->policy->evaluate(
            $row === null ? null : $this->factsFromRow($row),
            $today,
        );
    }

    public function findCurrentDriver(int $companyId, int $equipmentId, string $today): ?int
    {
        if ($companyId <= 0 || $equipmentId <= 0) {
            return null;
        }

        $row = $this->assignmentQuery($companyId)
            ->where('a.equipo_id', $equipmentId)
            ->where('a.fecha_desde <=', $today)
            ->orderBy('a.fecha_desde', 'DESC')
            ->orderBy('a.id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        if ($row === null) {
            return null;
        }

        $eligibility = $this->policy->evaluate($this->factsFromRow($row), $today);
        if ($eligibility->isBlocked()) {
            return null;
        }

        return (int) $row['empleado_id'];
    }

    private function assignmentQuery(int $companyId): BaseBuilder
    {
        return $this->database->table('employee_equipment_assignments a')
            ->select('a.empleado_id, a.fecha_desde, a.fecha_hasta')
            ->select('emp.activo empleado_activo, emp.deleted_at empleado_deleted_at')
            ->select('e.estado equipo_estado, e.deleted_at equipo_deleted_at')
            ->select('te.controla_km')
            ->select('co.estado empresa_estado, co.deleted_at empresa_deleted_at')
            ->select('co.notificaciones_whatsapp_habilitadas')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id', 'left')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id', 'left')
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id', 'left')
            ->join('empresas co', 'co.id = a.empresa_id', 'left')
            ->where('a.empresa_id', $companyId)
            ->where('a.rol', 'CHOFER');
    }

    /** @param array<string,mixed> $row */
    private function factsFromRow(array $row): DriverAssignmentFacts
    {
        $employeeFound = ($row['empleado_activo'] ?? null) !== null;
        $equipmentFound = ($row['equipo_estado'] ?? null) !== null;
        $typeFound = ($row['controla_km'] ?? null) !== null;
        $companyFound = ($row['empresa_estado'] ?? null) !== null;

        return new DriverAssignmentFacts(
            startsOn: (string) $row['fecha_desde'],
            endsOn: $row['fecha_hasta'] === null ? null : (string) $row['fecha_hasta'],
            employeeActive: (int) ($row['empleado_activo'] ?? 0) === 1,
            employeeDeleted: ! $employeeFound || ($row['empleado_deleted_at'] ?? null) !== null,
            equipmentState: (string) ($row['equipo_estado'] ?? ''),
            equipmentDeleted: ! $equipmentFound || ($row['equipo_deleted_at'] ?? null) !== null,
            typeControlsKilometers: $typeFound && (int) $row['controla_km'] === 1,
            companyActive: (int) ($row['empresa_estado'] ?? 0) === 1,
            companyDeleted: ! $companyFound || ($row['empresa_deleted_at'] ?? null) !== null,
            companyWhatsAppEnabled: (int) ($row['notificaciones_whatsapp_habilitadas'] ?? 0) === 1,
        );
    }
}
