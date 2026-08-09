<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\WorkOrders;

use App\Domain\WorkOrders\WorkOrderNumber;
use DomainException;
use PHPUnit\Framework\TestCase;

final class WorkOrderNumberTest extends TestCase
{
    public function testFormatsAndParsesAnnualSequence(): void
    {
        $number = WorkOrderNumber::fromSequence(2026, 42);

        self::assertSame('OT-2026-000042', $number->value());
        self::assertSame(42, WorkOrderNumber::fromString($number->value())->sequence());
    }

    public function testRejectsInvalidFormat(): void
    {
        $this->expectException(DomainException::class);

        WorkOrderNumber::fromString('OT-26-42');
    }
}
