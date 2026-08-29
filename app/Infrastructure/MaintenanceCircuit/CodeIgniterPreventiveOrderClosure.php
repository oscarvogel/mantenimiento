<?php

declare(strict_types=1);

namespace App\Infrastructure\MaintenanceCircuit;

use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\Port\PreventiveOrderClosurePort;
use App\Application\Measurement\RegisterReadingCommand;
use App\Application\Measurement\RegisterReadingHandler;
use App\Application\PreventiveMaintenance\RecalcularPlanTrasCierre;
use App\Application\WorkOrders\PreparePreventiveWorkOrderClosure;
use App\Application\WorkOrders\PreparePreventiveWorkOrderClosureCommand;
use App\Infrastructure\Assets\CodeIgniterEquipmentRepository;
use App\Infrastructure\Measurement\CodeIgniterReadingRepository;
use App\Infrastructure\Measurement\CodeIgniterUnitOfWork;
use App\Infrastructure\PreventiveMaintenance\CodeIgniterPlanMantenimientoRepository;
use App\Infrastructure\PreventiveMaintenance\CodeIgniterServiceTypeGateway;
use App\Infrastructure\PreventiveMaintenance\DecimalHours;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderRepository;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderTransaction;
use App\Infrastructure\WorkOrders\SystemClock;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DomainException;

final class CodeIgniterPreventiveOrderClosure implements PreventiveOrderClosurePort
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function close(int $companyId, ?array $branchIds, int $orderId, array $closure, int $actorUserId): array
    {
        $repository = new CodeIgniterWorkOrderRepository($this->database);
        $transaction = new CodeIgniterWorkOrderTransaction($this->database);
        $prepare = new PreparePreventiveWorkOrderClosure($repository, new SystemClock());
        $equipmentRepository = new CodeIgniterEquipmentRepository($this->database);
        $registerReading = new RegisterReadingHandler(
            $equipmentRepository,
            new CodeIgniterReadingRepository($this->database),
            new CodeIgniterUnitOfWork($this->database),
        );
        $recalculate = new RecalcularPlanTrasCierre(
            new CodeIgniterPlanMantenimientoRepository($this->database),
            new CodeIgniterServiceTypeGateway($this->database),
        );
        $actor = new ActorContext(
            $actorUserId,
            $companyId,
            false,
            $branchIds === null,
            [],
            ['ordenes.cerrar', 'lecturas.cargar'],
            $branchIds ?? [],
        );

        return $transaction->run(function () use ($companyId, $branchIds, $orderId, $closure, $actorUserId, $repository, $prepare, $registerReading, $recalculate, $actor): array {
            $taskBuilder = $this->database->table('orden_tareas t')
                ->select('t.id')->join('ordenes_trabajo o', 'o.id=t.orden_id AND o.empresa_id=t.empresa_id', 'inner')
                ->where('t.empresa_id', $companyId)->where('t.orden_id', $orderId);
            if ($branchIds !== null) {
                if ($branchIds === []) {
                    throw new DomainException('No hay sucursales autorizadas para cerrar la orden.');
                }
                $taskBuilder->whereIn('o.sucursal_id', $branchIds);
            }
            $taskRows = $taskBuilder->orderBy('t.orden')->get()->getResultArray();
            if ($taskRows === []) {
                throw new DomainException('La orden no existe, no tiene tareas o queda fuera del alcance autorizado.');
            }

            $workByTask = [];
            $deferredResults = [];
            $taskResults = is_array($closure['tareas'] ?? null) ? $closure['tareas'] : null;
            foreach ($taskRows as $task) {
                $taskId = (int) $task['id'];
                if ($taskResults === null) {
                    $workByTask[$taskId] = (string) $closure['trabajo_realizado'];
                    continue;
                }

                $result = $taskResults[$taskId] ?? null;
                if (! is_array($result)) {
                    throw new DomainException('Debe indicar el resultado de todas las tareas de la OT.');
                }

                $status = (string) ($result['resultado'] ?? '');
                $detail = (string) ($result['detalle'] ?? '');
                if ($status === 'REALIZADA') {
                    $workByTask[$taskId] = $detail !== '' ? $detail : 'Tarea realizada.';
                    continue;
                }

                if (! in_array($status, ['PENDIENTE', 'NO_APLICA'], true)) {
                    throw new DomainException('El resultado informado para una tarea no es válido.');
                }

                $workByTask[$taskId] = ($status === 'PENDIENTE' ? 'No realizada: ' : 'No aplica: ') . $detail;
                $deferredResults[$taskId] = ['estado' => $status, 'detalle' => $detail];
            }

            $prepared = $prepare->execute($actor, new PreparePreventiveWorkOrderClosureCommand(
                $orderId,
                $workByTask,
                $closure['km_salida'],
                $closure['horas_salida'],
            ));
            $serviceDate = new DateTimeImmutable((string) $closure['fecha_servicio'] . ' 12:00:00');

            if ($prepared->outputKilometres !== null || $prepared->outputHours !== null) {
                $registerReading->execute($actor, new RegisterReadingCommand(
                    $prepared->workOrder->equipmentId(),
                    $serviceDate,
                    $prepared->outputKilometres,
                    $prepared->outputHours,
                    'ORDEN_TRABAJO',
                    (string) $orderId,
                    null,
                    'Lectura registrada al cerrar ' . $prepared->workOrder->number()->value(),
                ));
            }

            $next = $recalculate->execute(
                $companyId,
                (int) $prepared->workOrder->planId(),
                $branchIds,
                $serviceDate,
                $prepared->outputKilometres,
                DecimalHours::toTenths($prepared->outputHours),
                $actorUserId,
            );
            $repository->save($prepared->workOrder, $actorUserId);
            $this->persistCosts($companyId, $orderId, $closure, $actorUserId);

            foreach ($deferredResults as $taskId => $result) {
                $this->database->table('orden_tareas')
                    ->where('empresa_id', $companyId)
                    ->where('orden_id', $orderId)
                    ->where('id', $taskId)
                    ->update([
                        'estado' => $result['estado'],
                        'trabajo_realizado' => null,
                        'fecha_fin' => null,
                        'observaciones' => $result['detalle'],
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            }

            return [
                'numero' => $prepared->workOrder->number()->value(),
                'proximo_km' => $next['proximo_km'],
                'proximas_horas' => $next['proximas_horas_decimas'] === null
                    ? null
                    : number_format($next['proximas_horas_decimas'] / 10, 1, '.', ''),
                'proxima_fecha' => $next['proxima_fecha'],
                'costo_total' => $closure['costo_total'],
            ];
        });
    }

    /** @param array<string,mixed> $closure */
    private function persistCosts(int $companyId, int $orderId, array $closure, int $actorUserId): void
    {
        $updated = $this->database->table('ordenes_trabajo')
            ->where('empresa_id', $companyId)
            ->where('id', $orderId)
            ->update([
                'costo_mano_obra' => $closure['costo_mano_obra'],
                'costo_repuestos' => $closure['costo_repuestos'],
                'otros_costos' => $closure['otros_costos'],
                'costo_total' => $closure['costo_total'],
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $actorUserId,
            ]);

        if (! $updated) {
            throw new DomainException('No se pudieron guardar los costos de la orden de trabajo.');
        }
    }
}
