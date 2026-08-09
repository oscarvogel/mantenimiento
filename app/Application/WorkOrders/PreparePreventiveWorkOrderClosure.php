<?php

declare(strict_types=1);

namespace App\Application\WorkOrders;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\Port\Clock;
use App\Application\WorkOrders\Port\WorkOrderRepository;
use App\Domain\WorkOrders\WorkOrderClosure;
use DomainException;

final class PreparePreventiveWorkOrderClosure
{
    public function __construct(
        private readonly WorkOrderRepository $workOrders,
        private readonly Clock $clock,
    ) {
    }

    /**
     * El coordinador debe invocar este mÃ©todo dentro de su UnitOfWork y persistir
     * la OT solo despuÃ©s de registrar lectura, actualizar equipo y recalcular plan.
     */
    public function execute(
        ActorContext $actor,
        PreparePreventiveWorkOrderClosureCommand $command,
    ): PreparedPreventiveWorkOrderClosure {
        $scope = WorkOrderActorScope::forPermission($actor, 'ordenes.cerrar');
        $workOrder = $this->workOrders->findScopedForUpdate($command->workOrderId, $scope);
        if ($workOrder === null) {
            throw new DomainException('La OT no existe dentro del alcance autorizado.');
        }
        if ($workOrder->planId() === null || $workOrder->origin() !== 'PREVENTIVO_VENCIDO') {
            throw new DomainException('La OT no corresponde a un cierre preventivo.');
        }

        $completedAt = $this->clock->now();
        foreach ($command->workPerformedByTaskId as $taskId => $workPerformed) {
            $workOrder->completeTask((int) $taskId, $workPerformed, $completedAt, $actor->userId());
        }
        $closure = new WorkOrderClosure($completedAt, $command->outputKilometres, $command->outputHours);
        $workOrder->close($closure, $actor->userId());

        return new PreparedPreventiveWorkOrderClosure(
            $workOrder,
            $completedAt,
            $closure->outputKilometres(),
            $closure->outputHours(),
        );
    }
}
