<?php

declare(strict_types=1);

namespace App\Infrastructure\MaintenanceCircuit;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\GeneratePreventiveWorkOrder;
use App\Application\WorkOrders\GeneratePreventiveWorkOrderCommand;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Infrastructure\PreventiveMaintenance\CodeIgniterPlanMantenimientoRepository;
use App\Infrastructure\PreventiveMaintenance\CodeIgniterPreventiveAssetGateway;
use App\Infrastructure\PreventiveMaintenance\SystemClock as PreventiveClock;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderNumberGenerator;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderRepository;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderTransaction;
use App\Infrastructure\WorkOrders\SystemClock;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final readonly class CodeIgniterPreventiveOrderFromPlan
{
    public function __construct(private BaseConnection $database)
    {
    }

    public function generate(ActorContext $actor, int $planId): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('ordenes.editar')) {
            throw new DomainException('No tenés permiso para generar órdenes de trabajo.');
        }
        if ($planId <= 0) {
            throw new DomainException('La asignación preventiva no es válida.');
        }

        $companyId = (int) $actor->companyId();
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        $transaction = new CodeIgniterWorkOrderTransaction($this->database);

        return $transaction->run(function () use ($actor, $planId, $companyId, $branchIds, $transaction): int {
            $sql = 'SELECT p.id plan_id, p.tipo_servicio_id, e.id equipo_id, e.sucursal_id, e.km_actual, e.horas_actuales, ts.prioridad '
                . 'FROM planes_mantenimiento p '
                . 'INNER JOIN equipos e ON e.id=p.equipo_id AND e.empresa_id=p.empresa_id '
                . 'INNER JOIN tipos_servicio ts ON ts.id=p.tipo_servicio_id AND ts.empresa_id=p.empresa_id '
                . 'WHERE p.id=? AND p.empresa_id=? AND p.activo=1 AND p.deleted_at IS NULL '
                . 'AND e.estado=? AND e.deleted_at IS NULL AND ts.activo=1';
            $params = [$planId, $companyId, 'ACTIVO'];
            if ($branchIds !== null) {
                if ($branchIds === []) {
                    throw new DomainException('No hay sucursales autorizadas para generar la orden.');
                }
                $sql .= ' AND e.sucursal_id IN (' . implode(',', array_fill(0, count($branchIds), '?')) . ')';
                array_push($params, ...$branchIds);
            }

            $row = $this->database->query($sql . ' FOR UPDATE', $params)->getRowArray();
            if ($row === null) {
                throw new DomainException('La asignación preventiva no existe o queda fuera del alcance autorizado.');
            }

            $existing = $this->database->table('ordenes_trabajo')
                ->select('id')
                ->where('empresa_id', $companyId)
                ->where('plan_id', $planId)
                ->where('origen', 'PREVENTIVO')
                ->whereNotIn('estado', ['FINALIZADA', 'CANCELADA'])
                ->orderBy('id', 'DESC')
                ->get(1)->getRowArray();
            if ($existing !== null) {
                return (int) $existing['id'];
            }

            $plan = (new CodeIgniterPlanMantenimientoRepository($this->database))->findScoped($companyId, $planId, $branchIds, true);
            $asset = (new CodeIgniterPreventiveAssetGateway($this->database))->findScoped($companyId, (int) $row['equipo_id'], $branchIds);
            if ($plan === null || $asset === null) {
                throw new DomainException('No se pudo evaluar la asignación preventiva.');
            }

            $state = (new EvaluadorVencimiento())->evaluar($plan, $asset->currentUsage, (new PreventiveClock())->now())->estado();
            if (! in_array($state, [EstadoPlan::PROXIMO, EstadoPlan::VENCIDO], true)) {
                throw new DomainException('Sólo se puede generar una OT para un servicio próximo a vencer o vencido.');
            }

            $taskRows = $this->database->table('tipo_servicio_tareas st')
                ->select('st.tarea_id, st.obligatoria, st.orden, t.nombre')
                ->join('tareas_mantenimiento t', 't.id=st.tarea_id', 'inner')
                ->where('st.tipo_servicio_id', (int) $row['tipo_servicio_id'])
                ->where('t.activo', 1)
                ->orderBy('st.orden')
                ->get()->getResultArray();
            if ($taskRows === []) {
                throw new DomainException('El Servicio no tiene tareas activas configuradas para generar la OT.');
            }

            $tasks = array_map(static fn (array $task): array => [
                'catalog_task_id' => (int) $task['tarea_id'],
                'description' => (string) $task['nombre'],
                'required' => (int) $task['obligatoria'] === 1,
                'sequence' => (int) $task['orden'],
            ], $taskRows);

            $handler = new GeneratePreventiveWorkOrder(
                new CodeIgniterWorkOrderRepository($this->database),
                new CodeIgniterWorkOrderNumberGenerator($this->database),
                $transaction,
                new SystemClock(),
            );

            return $handler->execute($actor, new GeneratePreventiveWorkOrderCommand(
                $companyId,
                (int) $row['sucursal_id'],
                (int) $row['equipo_id'],
                $planId,
                null,
                (int) $row['tipo_servicio_id'],
                $actor->userId(),
                (string) ($row['prioridad'] ?: 'MEDIA'),
                $row['km_actual'] === null ? null : (int) $row['km_actual'],
                $row['horas_actuales'] === null ? null : (string) $row['horas_actuales'],
                $tasks,
            ));
        });
    }
}
