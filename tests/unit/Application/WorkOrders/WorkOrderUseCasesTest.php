<?php

declare(strict_types=1);

namespace Tests\Unit\Application\WorkOrders;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\ChangeWorkOrderState;
use App\Application\WorkOrders\ChangeWorkOrderStateCommand;
use App\Application\WorkOrders\GeneratePreventiveWorkOrder;
use App\Application\WorkOrders\GeneratePreventiveWorkOrderCommand;
use App\Application\WorkOrders\Port\Clock;
use App\Application\WorkOrders\Port\WorkOrderNumberGenerator;
use App\Application\WorkOrders\Port\WorkOrderRepository;
use App\Application\WorkOrders\Port\WorkOrderTransaction;
use App\Application\WorkOrders\PreparePreventiveWorkOrderClosure;
use App\Application\WorkOrders\PreparePreventiveWorkOrderClosureCommand;
use App\Application\WorkOrders\StartWorkOrder;
use App\Application\WorkOrders\StartWorkOrderCommand;
use App\Application\WorkOrders\WorkOrderActorScope;
use App\Domain\WorkOrders\WorkOrder;
use App\Domain\WorkOrders\WorkOrderNumber;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Domain\WorkOrders\WorkOrderTask;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class WorkOrderUseCasesTest extends TestCase
{
    public function testGeneratesIssuedPreventiveOrderInsideTransaction(): void
    {
        $repository = new InMemoryWorkOrderRepository();
        $transaction = new ImmediateWorkOrderTransaction();
        $handler = new GeneratePreventiveWorkOrder(
            $repository,
            new FixedWorkOrderNumberGenerator(),
            $transaction,
            new FixedClock(),
        );

        $id = $handler->execute($this->actor(['ordenes.editar']), new GeneratePreventiveWorkOrderCommand(
            1,
            2,
            3,
            4,
            5,
            6,
            10,
            'ALTA',
            1100,
            null,
            [['catalog_task_id' => 9, 'description' => 'Realizar service preventivo', 'required' => true, 'sequence' => 1]],
        ));

        self::assertSame(77, $id);
        self::assertSame(WorkOrderStatus::ISSUED, $repository->order?->status());
        self::assertSame('OT-2026-000001', $repository->order?->number()->value());
        self::assertSame(1, $transaction->calls);
    }

    public function testRejectsGenerationOutsideActorCompany(): void
    {
        $handler = new GeneratePreventiveWorkOrder(
            new InMemoryWorkOrderRepository(),
            new FixedWorkOrderNumberGenerator(),
            new ImmediateWorkOrderTransaction(),
            new FixedClock(),
        );

        $this->expectException(DomainException::class);
        $handler->execute($this->actor(['ordenes.editar']), new GeneratePreventiveWorkOrderCommand(
            2, 2, 3, 4, 5, 6, 10, 'MEDIA', null, null,
            [['catalog_task_id' => null, 'description' => 'Inspección', 'required' => true, 'sequence' => 1]],
        ));
    }

    public function testStartsScopedIssuedOrder(): void
    {
        $repository = new InMemoryWorkOrderRepository($this->persistedOrder(WorkOrderStatus::ISSUED));
        $handler = new StartWorkOrder($repository, new ImmediateWorkOrderTransaction(), new FixedClock());

        $handler->execute($this->actor(['ordenes.editar']), new StartWorkOrderCommand(50, 1100, null));

        self::assertSame(WorkOrderStatus::IN_PROGRESS, $repository->order?->status());
        self::assertSame(1, $repository->saveCalls);
    }

    public function testPutsInProgressOrderOnHoldForParts(): void
    {
        $repository = new InMemoryWorkOrderRepository($this->persistedOrder(WorkOrderStatus::IN_PROGRESS));
        $transaction = new ImmediateWorkOrderTransaction();
        $handler = new ChangeWorkOrderState($repository, $transaction, new FixedClock());

        $handler->execute($this->actor(['ordenes.editar']), new ChangeWorkOrderStateCommand(
            50,
            'esperar_repuestos',
            'Esperando filtro de aceite',
        ));

        self::assertSame(WorkOrderStatus::WAITING_FOR_PARTS, $repository->order?->status());
        self::assertSame('Esperando filtro de aceite', $repository->order?->waitingReason());
        self::assertSame(1, $repository->saveCalls);
        self::assertSame(1, $transaction->calls);
    }

    public function testResumesOrderWaitingForParts(): void
    {
        $order = $this->persistedOrder(WorkOrderStatus::IN_PROGRESS);
        $order->putOnHold('Esperando filtro de aceite', new DateTimeImmutable('2026-08-08 09:30:00'), 10);
        $order->releaseStateChanges();
        $repository = new InMemoryWorkOrderRepository($order);
        $handler = new ChangeWorkOrderState($repository, new ImmediateWorkOrderTransaction(), new FixedClock());

        $handler->execute($this->actor(['ordenes.editar']), new ChangeWorkOrderStateCommand(50, 'reanudar'));

        self::assertSame(WorkOrderStatus::IN_PROGRESS, $repository->order?->status());
        self::assertNull($repository->order?->waitingReason());
    }

    public function testCancelsIssuedOrderWithReason(): void
    {
        $repository = new InMemoryWorkOrderRepository($this->persistedOrder(WorkOrderStatus::ISSUED));
        $handler = new ChangeWorkOrderState($repository, new ImmediateWorkOrderTransaction(), new FixedClock());

        $handler->execute($this->actor(['ordenes.editar']), new ChangeWorkOrderStateCommand(
            50,
            'cancelar',
            'Trabajo ya realizado previamente',
        ));

        self::assertSame(WorkOrderStatus::CANCELLED, $repository->order?->status());
        self::assertSame('Trabajo ya realizado previamente', $repository->order?->cancellationReason());
    }

    public function testRejectsInvalidLifecycleTransition(): void
    {
        $repository = new InMemoryWorkOrderRepository($this->persistedOrder(WorkOrderStatus::ISSUED));
        $handler = new ChangeWorkOrderState($repository, new ImmediateWorkOrderTransaction(), new FixedClock());

        $this->expectException(DomainException::class);
        $handler->execute($this->actor(['ordenes.editar']), new ChangeWorkOrderStateCommand(50, 'reanudar'));
    }

    public function testPreparesClosureWithoutPersistingCrossContextEffects(): void
    {
        $repository = new InMemoryWorkOrderRepository($this->persistedOrder(WorkOrderStatus::IN_PROGRESS));
        $handler = new PreparePreventiveWorkOrderClosure($repository, new FixedClock());

        $prepared = $handler->execute(
            $this->actor(['ordenes.cerrar']),
            new PreparePreventiveWorkOrderClosureCommand(50, [70 => 'Se realizó el service preventivo completo.'], 1120, null),
        );

        self::assertSame(WorkOrderStatus::COMPLETED, $prepared->workOrder->status());
        self::assertSame(1120, $prepared->outputKilometres);
        self::assertSame(0, $repository->saveCalls, 'El coordinador externo debe persistir al final de su UoW.');
    }

    private function actor(array $permissions): ActorContext
    {
        return new ActorContext(10, 1, false, true, ['Administrador'], $permissions, []);
    }

    private function persistedOrder(WorkOrderStatus $status): WorkOrder
    {
        return WorkOrder::reconstitute(
            50,
            WorkOrderNumber::fromSequence(2026, 1),
            1,
            2,
            3,
            'PREVENTIVO_VENCIDO',
            4,
            5,
            6,
            'MEDIA',
            10,
            new DateTimeImmutable('2026-08-08 08:00:00'),
            $status === WorkOrderStatus::IN_PROGRESS ? new DateTimeImmutable('2026-08-08 09:00:00') : null,
            null,
            1100,
            null,
            null,
            null,
            $status,
            null,
            null,
            [WorkOrderTask::reconstitute(70, 9, 'Realizar service preventivo', true, 1, 'PENDIENTE', null, null, null, null, null)],
        );
    }
}

final class InMemoryWorkOrderRepository implements WorkOrderRepository
{
    public int $saveCalls = 0;

    public function __construct(public ?WorkOrder $order = null)
    {
    }

    public function add(WorkOrder $workOrder, int $actorUserId): int
    {
        $this->order = $workOrder;
        return 77;
    }

    public function findScopedForUpdate(int $workOrderId, WorkOrderActorScope $scope): ?WorkOrder
    {
        return $this->order;
    }

    public function save(WorkOrder $workOrder, int $actorUserId): void
    {
        $this->order = $workOrder;
        $this->saveCalls++;
    }
}

final class ImmediateWorkOrderTransaction implements WorkOrderTransaction
{
    public int $calls = 0;

    public function run(callable $operation): mixed
    {
        $this->calls++;
        return $operation();
    }
}

final class FixedClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-08 10:00:00');
    }
}

final class FixedWorkOrderNumberGenerator implements WorkOrderNumberGenerator
{
    public function next(int $companyId, int $year): WorkOrderNumber
    {
        return WorkOrderNumber::fromSequence($year, 1);
    }
}
