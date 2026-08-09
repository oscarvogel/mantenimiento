<?php

declare(strict_types=1);

use App\Domain\PreventiveMaintenance\AvisoPlan;
use App\Domain\PreventiveMaintenance\EstadoGestionAviso;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\UsoActual;
use PHPUnit\Framework\TestCase;

final class AvisoPlanTest extends TestCase
{
    public function testNoticeCycleKeyIsStableAndConversionHappensOnlyOnce(): void
    {
        $plan = PlanMantenimiento::reconstituir(
            9, 1, 2, 3,
            10_000, null, null,
            1_000, null, null,
            90_000, null, null,
            100_000, null, null,
            'MEDIA', true, null,
        );
        $evaluation = (new EvaluadorVencimiento())->evaluar(
            $plan,
            new UsoActual(100_000, null),
            new DateTimeImmutable('2026-01-01'),
        );

        $first  = AvisoPlan::paraPlanVencido($plan, $evaluation, new DateTimeImmutable('2026-01-01 08:00:00'));
        $second = AvisoPlan::paraPlanVencido($plan, $evaluation, new DateTimeImmutable('2026-01-01 09:00:00'));

        self::assertSame($first->claveCiclo(), $second->claveCiclo());
        self::assertSame(64, strlen($first->claveCiclo()));

        $persisted = AvisoPlan::reconstituir(
            4,
            $first->empresaId(),
            $first->planId(),
            $first->equipoId(),
            $first->claveCiclo(),
            $first->criteriosDisparadores(),
            $first->fechaDeteccion(),
            EstadoGestionAviso::PENDIENTE,
            null,
            null,
        );
        $persisted->marcarConvertido(new DateTimeImmutable('2026-01-02'));
        self::assertSame(EstadoGestionAviso::CONVERTIDO, $persisted->estadoGestion());

        $this->expectException(DomainException::class);
        $persisted->marcarConvertido(new DateTimeImmutable('2026-01-03'));
    }
}
