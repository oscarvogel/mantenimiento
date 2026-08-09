<?php

declare(strict_types=1);

namespace App\Infrastructure\MaintenanceCircuit;

use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\Port\PreventiveOrderFromNoticePort;
use App\Application\WorkOrders\GeneratePreventiveWorkOrder;
use App\Application\WorkOrders\GeneratePreventiveWorkOrderCommand;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderNumberGenerator;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderRepository;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderTransaction;
use App\Infrastructure\WorkOrders\SystemClock;
use App\Infrastructure\PreventiveMaintenance\CodeIgniterPlanMantenimientoRepository;
use App\Infrastructure\PreventiveMaintenance\CodeIgniterPreventiveAssetGateway;
use App\Infrastructure\PreventiveMaintenance\SystemClock as PreventiveClock;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final class CodeIgniterPreventiveOrderFromNotice implements PreventiveOrderFromNoticePort
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function generate(int $companyId, ?array $branchIds, int $noticeId, int $responsibleUserId, int $actorUserId): int
    {
        $transaction = new CodeIgniterWorkOrderTransaction($this->database);
        $repository = new CodeIgniterWorkOrderRepository($this->database);
        $handler = new GeneratePreventiveWorkOrder(
            $repository,
            new CodeIgniterWorkOrderNumberGenerator($this->database),
            $transaction,
            new SystemClock(),
        );
        $actor = $this->actor($companyId, $branchIds, $actorUserId, ['ordenes.editar']);

        return $transaction->run(function () use ($companyId, $branchIds, $noticeId, $responsibleUserId, $actorUserId, $handler, $actor): int {
            $sql = 'SELECT a.id aviso_id, a.estado_gestion, a.estado_calculado, p.id plan_id, p.tipo_servicio_id, '
                . 'p.prioridad, e.id equipo_id, e.sucursal_id, e.km_actual, e.horas_actuales '
                . 'FROM avisos_plan a INNER JOIN planes_mantenimiento p ON p.id=a.plan_id AND p.empresa_id=a.empresa_id '
                . 'INNER JOIN equipos e ON e.id=a.equipo_id AND e.empresa_id=a.empresa_id '
                . 'WHERE a.id=? AND a.empresa_id=? AND a.estado_gestion=? AND p.activo=1 AND e.estado=? AND e.deleted_at IS NULL';
            $params = [$noticeId, $companyId, 'PENDIENTE', 'ACTIVO'];
            if ($branchIds !== null) {
                if ($branchIds === []) {
                    throw new DomainException('No hay sucursales autorizadas para generar la orden.');
                }
                $sql .= ' AND e.sucursal_id IN (' . implode(',', array_fill(0, count($branchIds), '?')) . ')';
                array_push($params, ...$branchIds);
            }
            $notice = $this->database->query($sql . ' FOR UPDATE', $params)->getRowArray();
            if ($notice === null || $notice['estado_calculado'] !== 'VENCIDO') {
                throw new DomainException('El aviso vencido no existe o queda fuera del alcance autorizado.');
            }

            $plan = (new CodeIgniterPlanMantenimientoRepository($this->database))->findScoped(
                $companyId,
                (int) $notice['plan_id'],
                $branchIds,
                true,
            );
            $asset = (new CodeIgniterPreventiveAssetGateway($this->database))->findScoped(
                $companyId,
                (int) $notice['equipo_id'],
                $branchIds,
            );
            if ($plan === null || $asset === null || (new EvaluadorVencimiento())->evaluar(
                $plan,
                $asset->currentUsage,
                (new PreventiveClock())->now(),
            )->estado() !== EstadoPlan::VENCIDO) {
                throw new DomainException('El plan ya no está vencido; se canceló la generación de la orden.');
            }

            $responsible = $this->database->table('usuarios')->select('id')
                ->where('id', $responsibleUserId)->where('empresa_id', $companyId)
                ->where('activo', 1)->where('es_superadmin', 0)->where('deleted_at', null)
                ->get()->getRowArray();
            if ($responsible === null) {
                throw new DomainException('El responsable no pertenece a la empresa o está inactivo.');
            }

            $taskRows = $this->database->table('tipo_servicio_tareas st')
                ->select('st.tarea_id, st.obligatoria, st.orden, t.nombre, t.descripcion')
                ->join('tareas_mantenimiento t', 't.id=st.tarea_id', 'inner')
                ->where('st.tipo_servicio_id', (int) $notice['tipo_servicio_id'])
                ->where('t.activo', 1)->orderBy('st.orden')->get()->getResultArray();
            if ($taskRows === []) {
                throw new DomainException('El tipo de servicio no tiene tareas activas configuradas.');
            }
            $tasks = array_map(static fn (array $task): array => [
                'catalog_task_id' => (int) $task['tarea_id'],
                'description' => (string) $task['nombre'],
                'required' => (int) $task['obligatoria'] === 1,
                'sequence' => (int) $task['orden'],
            ], $taskRows);

            $orderId = $handler->execute($actor, new GeneratePreventiveWorkOrderCommand(
                $companyId,
                (int) $notice['sucursal_id'],
                (int) $notice['equipo_id'],
                (int) $notice['plan_id'],
                $noticeId,
                (int) $notice['tipo_servicio_id'],
                $responsibleUserId,
                (string) $notice['prioridad'],
                $notice['km_actual'] === null ? null : (int) $notice['km_actual'],
                $notice['horas_actuales'] === null ? null : (string) $notice['horas_actuales'],
                $tasks,
            ));

            $updated = $this->database->table('avisos_plan')->where('id', $noticeId)
                ->where('empresa_id', $companyId)->where('estado_gestion', 'PENDIENTE')
                ->update([
                    'estado_gestion' => 'CONVERTIDO', 'fecha_resolucion' => date('Y-m-d H:i:s'),
                    'motivo_resolucion' => 'Convertido en OT ' . $orderId, 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if (! $updated || $this->database->affectedRows() !== 1) {
                throw new DomainException('El aviso ya fue procesado por otra ejecución.');
            }

            return $orderId;
        });
    }

    /** @param list<int>|null $branchIds @param list<string> $permissions */
    private function actor(int $companyId, ?array $branchIds, int $userId, array $permissions): ActorContext
    {
        return new ActorContext($userId, $companyId, false, $branchIds === null, [], $permissions, $branchIds ?? []);
    }
}
