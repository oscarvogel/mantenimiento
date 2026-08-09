<?php

declare(strict_types=1);

namespace App\Application\WorkOrders;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\Port\Clock;
use App\Application\WorkOrders\Port\WorkOrderNumberGenerator;
use App\Application\WorkOrders\Port\WorkOrderRepository;
use App\Application\WorkOrders\Port\WorkOrderTransaction;
use App\Domain\WorkOrders\WorkOrder;
use App\Domain\WorkOrders\WorkOrderTask;
use DomainException;

final class GeneratePreventiveWorkOrder
{
    public function __construct(
        private readonly WorkOrderRepository $workOrders,
        private readonly WorkOrderNumberGenerator $numbers,
        private readonly WorkOrderTransaction $transaction,
        private readonly Clock $clock,
    ) {
    }

    public function execute(ActorContext $actor, GeneratePreventiveWorkOrderCommand $command): int
    {
        $scope = WorkOrderActorScope::forPermission($actor, 'ordenes.editar');
        if ($scope->companyId() !== $command->companyId) {
            throw new DomainException('El aviso preventivo no pertenece a la empresa activa.');
        }
        $scope->assertBranch($command->branchId);

        return $this->transaction->run(function () use ($actor, $command): int {
            $now = $this->clock->now();
            $tasks = array_map(
                static fn (array $task): WorkOrderTask => WorkOrderTask::snapshot(
                    $task['catalog_task_id'],
                    $task['description'],
                    $task['required'],
                    $task['sequence'],
                ),
                $command->tasks,
            );
            $number = $this->numbers->next($command->companyId, (int) $now->format('Y'));
            $workOrder = WorkOrder::createPreventive(
                $number,
                $command->companyId,
                $command->branchId,
                $command->equipmentId,
                $command->planId,
                $command->preventiveNoticeId,
                $command->serviceTypeId,
                $command->priority,
                $command->responsibleUserId,
                $now,
                $command->inputKilometres,
                $command->inputHours,
                $tasks,
                $actor->userId(),
            );
            $workOrder->authorize($now, $actor->userId());

            return $this->workOrders->add($workOrder, $actor->userId());
        });
    }
}
