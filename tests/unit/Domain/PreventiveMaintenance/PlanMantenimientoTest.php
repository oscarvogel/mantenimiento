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

    public function testReconfigurarRecalculatesEveryNextTargetFromReinformedBases(): void
    {
        $plan = PlanMantenimiento::reconfigurar(
            12, 1, 2, 3,
            10_000, 500, 30,
            1_000, 50, 5,
            95_500, 1_150, new DateTimeImmutable('2026-02-01'),
            'ALTA', 'Ajuste del ciclo', 7, 21,
        );

        self::assertSame(12, $plan->id());
        self::assertSame(1, $plan->empresaId());
        self::assertSame(2, $plan->equipoId());
        self::assertSame(3, $plan->tipoServicioId());
        self::assertSame(7, $plan->origenPlantillaId());
        self::assertSame(21, $plan->origenPlantillaItemId());
        self::assertSame(105_500, $plan->proximoKm());
        self::assertSame(1_650, $plan->proximasHorasDecimas());
        self::assertSame('2026-03-03', $plan->proximaFecha()?->format('Y-m-d'));
        self::assertSame('ALTA', $plan->prioridad());
        self::assertSame('Ajuste del ciclo', $plan->observaciones());
    }

    public function testReconfigurarKeepsUninformedCriteriaInSinDatos(): void
    {
        $plan = PlanMantenimiento::reconfigurar(
            12, 1, 2, 3,
            10_000, null, null,
            1_000, null, null,
            null, null, null,
            'MEDIA', null,
        );

        self::assertNull($plan->baseKm());
        self::assertNull($plan->proximoKm());
        self::assertNull($plan->baseHorasDecimas());
        self::assertNull($plan->proximasHorasDecimas());
        self::assertNull($plan->baseFecha());
        self::assertNull($plan->proximaFecha());
    }

    public function testReconfigurarRejectsNegativeBaseOrTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanMantenimiento::reconfigurar(12, 1, 2, 3, 10_000, null, null, 1_000, null, null, -5, null, null, 'MEDIA', null);
    }

    public function testReconfigurarRejectsWarningEqualToOrAboveInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanMantenimiento::reconfigurar(12, 1, 2, 3, 10_000, null, null, 10_000, null, null, 95_000, null, null, 'MEDIA', null);
    }

    public function testReconfigurarRejectsInvalidPriority(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanMantenimiento::reconfigurar(12, 1, 2, 3, 10_000, null, null, 1_000, null, null, 95_000, null, null, 'URGENTE', null);
    }

    public function testReconfigurarRejectsInvalidIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanMantenimiento::reconfigurar(0, 1, 2, 3, 10_000, null, null, 1_000, null, null, 95_000, null, null, 'MEDIA', null);
    }
}
