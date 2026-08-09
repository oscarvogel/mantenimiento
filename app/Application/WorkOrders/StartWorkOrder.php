<?php

declare(strict_types=1);

namespace App\Application\WorkOrders;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\Port\Clock;
use App\Application\WorkOrders\Port\WorkOrderRepository;
use App\Application\WorkOrders\Port\WorkOrderTransaction;
use DomainException;

final class StartWorkOrder
{
    public function __construct(
        private readonly WorkOrderRepository $workOrders,
        private readonly WorkOrderTransaction $transaction,
        private readonly Clock $clock,
    ) {
    }

    public function execute(ActorContext $actor, StartWorkOrderCommand $command): void
    {
        $scope = WorkOrderActorScope::forPermission($actor, 'ordenes.editar');

        $this->transaction->run(function () use ($actor, $command, $scope): void {
            $workOrder = $this->workOrders->findScopedForUpdate($command->workOrderId, $scope);
            if ($workOrder === null) {
                throw new DomainException('La OT no existe dentro del alcance autorizado.');
            }

            $workOrder->start($this->clock->now(), $actor->userId(), $command->inputKilometres, $command->inputHours);
            $this->workOrders->save($workOrder, $actor->userId());
        });
    }
}
