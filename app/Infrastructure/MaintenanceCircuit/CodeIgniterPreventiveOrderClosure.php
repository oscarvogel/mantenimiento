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
        $recalculate = new RecalcularPlanTrasCierre(new CodeIgniterPlanMantenimientoRepository($this->database));
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
            foreach ($taskRows as $task) {
                $workByTask[(int) $task['id']] = (string) $closure['trabajo_realizado'];
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

            $recalculate->execute(
                $companyId,
                (int) $prepared->workOrder->planId(),
                $branchIds,
                $serviceDate,
                $prepared->outputKilometres,
                DecimalHours::toTenths($prepared->outputHours),
                $actorUserId,
            );
            $repository->save($prepared->workOrder, $actorUserId);

            $plan = $this->database->table('planes_mantenimiento')
                ->select('proximo_km, proximas_horas, proxima_fecha')
                ->where('empresa_id', $companyId)->where('id', (int) $prepared->workOrder->planId())
                ->get()->getRowArray();

            return [
                'numero' => $prepared->workOrder->number()->value(),
                'proximo_km' => $plan['proximo_km'] ?? null,
                'proximas_horas' => $plan['proximas_horas'] ?? null,
                'proxima_fecha' => $plan['proxima_fecha'] ?? null,
            ];
        });
    }
}
