<?php

declare(strict_types=1);

use App\Domain\PreventiveMaintenance\CriterioPlan;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\UsoActual;
use PHPUnit\Framework\TestCase;

final class HistoricalBaseTest extends TestCase
{
    public function testKnownHistoricalBaseCalculatesNextKilometres(): void
    {
        $plan = PlanMantenimiento::asignar(
            1, 2, 3,
            20_000, null, null,
            2_000, null, null,
            170_000, null, null,
        );

        self::assertSame(170_000, $plan->baseKm());
        self::assertSame(190_000, $plan->proximoKm());

        $evaluation = (new EvaluadorVencimiento())->evaluar(
            $plan,
            new UsoActual(185_000, null),
            new DateTimeImmutable('2026-08-11'),
        );

        self::assertSame(EstadoPlan::AL_DIA, $evaluation->estado());
    }

    public function testUnknownHistoricalBaseKeepsCriterionWithoutInventingCurrentReading(): void
    {
        $plan = PlanMantenimiento::asignar(
            1, 2, 3,
            20_000, null, null,
            2_000, null, null,
            null, null, null,
        );

        self::assertNull($plan->baseKm());
        self::assertNull($plan->proximoKm());

        $evaluation = (new EvaluadorVencimiento())->evaluar(
            $plan,
            new UsoActual(185_000, null),
            new DateTimeImmutable('2026-08-11'),
        );

        self::assertSame(EstadoPlan::SIN_DATOS, $evaluation->estado());
        self::assertSame([CriterioPlan::KILOMETRAJE], $evaluation->faltantes());
    }

    public function testUnknownDateBaseIsAlsoSinDatos(): void
    {
        $plan = PlanMantenimiento::asignar(
            1, 2, 3,
            null, null, 365,
            null, null, 30,
            null, null, null,
        );

        $evaluation = (new EvaluadorVencimiento())->evaluar(
            $plan,
            new UsoActual(null, null),
            new DateTimeImmutable('2026-08-11'),
        );

        self::assertSame(EstadoPlan::SIN_DATOS, $evaluation->estado());
        self::assertSame([CriterioPlan::FECHA], $evaluation->faltantes());
    }
}
