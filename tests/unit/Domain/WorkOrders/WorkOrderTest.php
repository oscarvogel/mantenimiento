<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\WorkOrders;

use App\Domain\WorkOrders\WorkOrder;
use App\Domain\WorkOrders\WorkOrderClosure;
use App\Domain\WorkOrders\WorkOrderNumber;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Domain\WorkOrders\WorkOrderTask;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class WorkOrderTest extends TestCase
{
    public function testPreventiveOrderCanBeIssuedStartedAndClosed(): void
    {
        $order = $this->draftOrder();
        $order->authorize($this->date('08:01'), 10);
        $order->start($this->date('09:00'), 10, 1000, '50.0');
        $order->completeTask(11, 'Se reemplazÃ³ aceite y filtro del motor.', $this->date('10:00'), 10);
        $order->close(new WorkOrderClosure($this->date('10:30'), 1010, '50.5'), 10);

        self::assertSame(WorkOrderStatus::COMPLETED, $order->status());
        self::assertSame(1010, $order->outputKilometres());
        self::assertSame('50.5', $order->outputHours());
        self::assertCount(4, $order->releaseStateChanges());
    }

    public function testCannotCloseWithoutCompletedWork(): void
    {
        $order = $this->draftOrder();
        $order->authorize($this->date('08:01'), 10);
        $order->start($this->date('09:00'), 10, 1000, null);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('al menos un trabajo realizado');

        $order->close(new WorkOrderClosure($this->date('10:30'), 1010, null), 10);
    }

    public function testCannotCloseWithUsageRegression(): void
    {
        $order = $this->draftOrder();
        $order->authorize($this->date('08:01'), 10);
        $order->start($this->date('09:00'), 10, 1000, '50.0');
        $order->completeTask(11, 'Se reemplazÃ³ aceite y filtro del motor.', $this->date('10:00'), 10);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('kilometraje de salida');

        $order->close(new WorkOrderClosure($this->date('10:30'), 999, '50.5'), 10);
    }

    public function testWaitingAndCancellationRequireAReason(): void
    {
        $order = $this->draftOrder();
        $order->authorize($this->date('08:01'), 10);
        $order->start($this->date('09:00'), 10, null, null);

        $this->expectException(DomainException::class);
        $order->putOnHold('no', $this->date('09:30'), 10);
    }

    public function testCannotResumeIssuedOrder(): void
    {
        $order = $this->draftOrder();
        $order->authorize($this->date('08:01'), 10);

        $this->expectException(DomainException::class);

        $order->resume($this->date('09:00'), 10);
    }

    private function draftOrder(): WorkOrder
    {
        $task = WorkOrderTask::reconstitute(
            11,
            5,
            'Cambio de aceite y filtro',
            true,
            1,
            'PENDIENTE',
            null,
            null,
            null,
            null,
            null,
        );

        return WorkOrder::createPreventive(
            WorkOrderNumber::fromSequence(2026, 1),
            1,
            1,
            1,
            1,
            1,
            1,
            'MEDIA',
            10,
            $this->date('08:00'),
            null,
            null,
            [$task],
            10,
        );
    }

    private function date(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-08 ' . $time . ':00');
    }
}
