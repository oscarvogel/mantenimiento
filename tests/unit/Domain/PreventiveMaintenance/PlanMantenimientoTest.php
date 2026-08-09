<?php

declare(strict_types=1);

use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use PHPUnit\Framework\TestCase;

final class PlanMantenimientoTest extends TestCase
{
    public function testCriticalCase10ClosureRecalculatesEveryConfiguredTargetFromActualOutput(): void
    {
        $plan = PlanMantenimiento::asignar(
            1, 2, 3,
            10_000, 500, 30,
            1_000, 50, 5,
            90_000, 1_000, new DateTimeImmutable('2026-01-01'),
        );

        $plan->recalcularDesdeCierre(new DateTimeImmutable('2026-02-10 18:30:00'), 102_500, 1_620);

        self::assertSame(102_500, $plan->baseKm());
        self::assertSame(112_500, $plan->proximoKm());
        self::assertSame(1_620, $plan->baseHorasDecimas());
        self::assertSame(2_120, $plan->proximasHorasDecimas());
        self::assertSame('2026-02-10', $plan->baseFecha()?->format('Y-m-d'));
        self::assertSame('2026-03-12', $plan->proximaFecha()?->format('Y-m-d'));
    }

    public function testClosureRequiresEveryConfiguredOutputReading(): void
    {
        $plan = PlanMantenimiento::asignar(
            1, 2, 3,
            10_000, null, null,
            1_000, null, null,
            90_000, null, null,
        );

        $this->expectException(DomainException::class);
        $plan->recalcularDesdeCierre(new DateTimeImmutable('2026-02-10'), null, null);
    }

    public function testRejectsPlanWithoutCriteria(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanMantenimiento::asignar(1, 2, 3, null, null, null, null, null, null, null, null, null);
    }

    public function testRejectsWarningEqualToInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanMantenimiento::asignar(1, 2, 3, 1_000, null, null, 1_000, null, null, 0, null, null);
    }
}
