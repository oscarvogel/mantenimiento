<?php

declare(strict_types=1);

use App\Domain\PreventiveMaintenance\CriterioPlan;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\UsoActual;
use PHPUnit\Framework\TestCase;

final class EvaluadorVencimientoTest extends TestCase
{
    private EvaluadorVencimiento $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new EvaluadorVencimiento();
    }

    public function testCriticalCase1ExpiresOnlyByDate(): void
    {
        $evaluation = $this->evaluator->evaluar(
            $this->datePlan(),
            new UsoActual(null, null),
            new DateTimeImmutable('2026-02-01'),
        );

        self::assertSame(EstadoPlan::VENCIDO, $evaluation->estado());
        self::assertSame([CriterioPlan::FECHA], $evaluation->vencidos());
    }

    public function testCriticalCase2ExpiresOnlyByKilometresAtExactTarget(): void
    {
        $plan = PlanMantenimiento::asignar(
            1, 2, 3,
            10_000, null, null,
            1_000, null, null,
            90_000, null, null,
        );

        $evaluation = $this->evaluator->evaluar($plan, new UsoActual(100_000, null), new DateTimeImmutable('2026-01-01'));

        self::assertSame(EstadoPlan::VENCIDO, $evaluation->estado());
        self::assertSame([CriterioPlan::KILOMETRAJE], $evaluation->vencidos());
    }

    public function testCriticalCase3ExpiresOnlyByHourMeter(): void
    {
        $plan = PlanMantenimiento::asignar(
            1, 2, 3,
            null, 500, null,
            null, 50, null,
            null, 1_000, null,
        );

        $evaluation = $this->evaluator->evaluar($plan, new UsoActual(null, 1_500), new DateTimeImmutable('2026-01-01'));

        self::assertSame(EstadoPlan::VENCIDO, $evaluation->estado());
        self::assertSame([CriterioPlan::HOROMETRO], $evaluation->vencidos());
    }

    public function testCriticalCase4CombinedPlanExpiresWhenDateArrivesFirst(): void
    {
        $plan = PlanMantenimiento::asignar(
            1, 2, 3,
            10_000, null, 30,
            1_000, null, 5,
            90_000, null, new DateTimeImmutable('2026-01-01'),
        );

        $evaluation = $this->evaluator->evaluar($plan, new UsoActual(95_000, null), new DateTimeImmutable('2026-01-31'));

        self::assertSame(EstadoPlan::VENCIDO, $evaluation->estado());
        self::assertSame([CriterioPlan::FECHA], $evaluation->vencidos());
    }

    public function testCriticalCase5CombinedPlanExpiresWhenKilometresArriveFirst(): void
    {
        $plan = PlanMantenimiento::asignar(
            1, 2, 3,
            10_000, null, 30,
            1_000, null, 5,
            90_000, null, new DateTimeImmutable('2026-01-01'),
        );

        $evaluation = $this->evaluator->evaluar($plan, new UsoActual(100_000, null), new DateTimeImmutable('2026-01-10'));

        self::assertSame(EstadoPlan::VENCIDO, $evaluation->estado());
        self::assertSame([CriterioPlan::KILOMETRAJE], $evaluation->vencidos());
    }

    public function testCriticalCase6WarningBoundaryIsInclusiveForEveryCriterion(): void
    {
        $plan = PlanMantenimiento::asignar(
            1, 2, 3,
            10_000, 500, 30,
            1_000, 50, 5,
            90_000, 1_000, new DateTimeImmutable('2026-01-01'),
        );

        $evaluation = $this->evaluator->evaluar(
            $plan,
            new UsoActual(99_000, 1_450),
            new DateTimeImmutable('2026-01-26'),
        );

        self::assertSame(EstadoPlan::PROXIMO, $evaluation->estado());
        self::assertSame(
            [CriterioPlan::FECHA, CriterioPlan::KILOMETRAJE, CriterioPlan::HOROMETRO],
            $evaluation->proximos(),
        );
    }

    public function testCriticalCase7MissingRequiredReadingTakesPrecedenceOverKnownExpiration(): void
    {
        $plan = PlanMantenimiento::asignar(
            1, 2, 3,
            10_000, null, 30,
            1_000, null, 5,
            90_000, null, new DateTimeImmutable('2026-01-01'),
        );

        $evaluation = $this->evaluator->evaluar($plan, new UsoActual(null, null), new DateTimeImmutable('2026-02-01'));

        self::assertSame(EstadoPlan::SIN_DATOS, $evaluation->estado());
        self::assertSame([CriterioPlan::KILOMETRAJE], $evaluation->faltantes());
        self::assertSame([CriterioPlan::FECHA], $evaluation->vencidos());
    }

    public function testPlanRemainsUpToDateBeforeEveryWarningThreshold(): void
    {
        $plan = PlanMantenimiento::asignar(
            1, 2, 3,
            10_000, 500, 30,
            1_000, 50, 5,
            90_000, 1_000, new DateTimeImmutable('2026-01-01'),
        );

        $evaluation = $this->evaluator->evaluar(
            $plan,
            new UsoActual(98_999, 1_449),
            new DateTimeImmutable('2026-01-25'),
        );

        self::assertSame(EstadoPlan::AL_DIA, $evaluation->estado());
    }

    private function datePlan(): PlanMantenimiento
    {
        return PlanMantenimiento::asignar(
            1, 2, 3,
            null, null, 30,
            null, null, 5,
            null, null, new DateTimeImmutable('2026-01-01'),
        );
    }
}
