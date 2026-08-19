<?php

declare(strict_types=1);

namespace App\Application\WorkOrders;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\Port\Clock;
use App\Application\WorkOrders\Port\WorkOrderRepository;
use App\Application\WorkOrders\Port\WorkOrderTransaction;
use DomainException;

final class ChangeWorkOrderState
{
    public function __construct(
        private readonly WorkOrderRepository $workOrders,
        private readonly WorkOrderTransaction $transaction,
        private readonly Clock $clock,
    ) {
    }

    public function execute(ActorContext $actor, ChangeWorkOrderStateCommand $command): void
    {
        $scope = WorkOrderActorScope::forPermission($actor, 'ordenes.editar');
        $action = mb_strtolower(trim($command->action));

        $this->transaction->run(function () use ($actor, $command, $scope, $action): void {
            $workOrder = $this->workOrders->findScopedForUpdate($command->workOrderId, $scope);
            if ($workOrder === null) {
                throw new DomainException('La OT no existe dentro del alcance autorizado.');
            }

            $now = $this->clock->now();
            match ($action) {
                'esperar_repuestos' => $workOrder->putOnHold((string) $command->reason, $now, $actor->userId()),
                'reanudar' => $workOrder->resume($now, $actor->userId()),
                'cancelar' => $workOrder->cancel((string) $command->reason, $now, $actor->userId()),
                default => throw new DomainException('La transición solicitada para la OT no es válida.'),
            };

            $this->workOrders->save($workOrder, $actor->userId());
        });
    }
}
