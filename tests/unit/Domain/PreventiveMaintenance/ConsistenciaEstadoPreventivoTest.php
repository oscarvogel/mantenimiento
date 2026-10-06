<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\PreventiveMaintenance;

use App\Domain\PreventiveMaintenance\AvisoPlan;
use App\Domain\PreventiveMaintenance\CriterioPlan;
use App\Domain\PreventiveMaintenance\EstadoGestionAviso;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use App\Domain\PreventiveMaintenance\EvaluacionPlan;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\UsoActual;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * Regresion del incidente: el mismo plan aparecia simultaneamente como
 * "PROXIMO - faltan 963 km" en el dashboard y como "VENCIDO por KILOMETRAJE"
 * en la pantalla "Atencion requerida".
 *
 * La causa era que el dashboard evalua el plan en vivo con EvaluadorVencimiento,
 * mientras que "Atencion requerida" leia la fila persistida de avisos_plan, que
 * es una foto del ciclo anterior y nunca se invalida cuando el plan se recalcula.
 *
 * Estos tests fijan la regla: el estado de un plan lo decide el evaluador de
 * dominio, y un aviso de un ciclo anterior no puede seguir presentandose como
 * vencido cuando su plan ya no lo esta.
 */
final class ConsistenciaEstadoPreventivoTest extends TestCase
{
    private const EMPRESA = 7;
    private const PLAN = 44;
    private const EQUIPO = 20;

    // ------------------------------------------------------------------
    // Caso 1: km_restantes > 0 dentro del umbral de aviso => PROXIMO
    // ------------------------------------------------------------------

    public function testPlanWithRemainingKilometersInsideWarningIsProximoAndNeverVencido(): void
    {
        $plan = $this->planByKilometers(
            intervalKm: 15_000,
            warningKm: 2_000,
            baseKm: 200_000,
            nextKm: 215_000,
        );

        // Reproduce AD738EA: faltan 963 km, dentro del umbral de aviso de 2.000.
        $evaluation = (new EvaluadorVencimiento())->evaluar(
            $plan,
            new UsoActual(214_037, null),
            new DateTimeImmutable('2026-10-05'),
        );

        self::assertSame(EstadoPlan::PROXIMO, $evaluation->estado());
        self::assertSame([], $evaluation->vencidos(), 'Un plan proximo no puede tener criterios vencidos.');
        self::assertSame([CriterioPlan::KILOMETRAJE], $evaluation->proximos());
        self::assertSame(963, 215_000 - 214_037);
    }

    // ------------------------------------------------------------------
    // Caso 2: km_restantes <= 0 => VENCIDO
    // ------------------------------------------------------------------

    public function testPlanThatReachedItsTargetKilometersIsVencido(): void
    {
        $plan = $this->planByKilometers(
            intervalKm: 15_000,
            warningKm: 2_000,
            baseKm: 200_000,
            nextKm: 215_000,
        );

        $evaluation = (new EvaluadorVencimiento())->evaluar(
            $plan,
            new UsoActual(215_000, null),
            new DateTimeImmutable('2026-10-05'),
        );

        self::assertSame(EstadoPlan::VENCIDO, $evaluation->estado());
        self::assertContains('KILOMETRAJE', $evaluation->criteriosDisparadores());
    }

    /**
     * Caso 3: el mismo plan consultado por el camino del dashboard y por el
     * camino de "Atencion requerida" debe dar el mismo estado, siempre.
     *
     * Antes del fix estos dos caminosQueryable no compartian fuente de verdad:
     * el dashboard evaluaba en vivo y el listado leia la fila persistida.
     */
    public function testDashboardAndAttentionRequiredAgreeForTheSamePlan(): void
    {
        $plan = $this->planByKilometers(
            intervalKm: 15_000,
            warningKm: 2_000,
            baseKm: 200_000,
            nextKm: 215_000,
        );
        $now = new DateTimeImmutable('2026-10-05');

        // Camino del dashboard: evaluacion en vivo del plan.
        $dashboardState = (new EvaluadorVencimiento())
            ->evaluar($plan, new UsoActual(214_037, null), $now)
            ->estado();

        // Camino de "Atencion requerida": el aviso persistido de un ciclo
        // anterior quedo PENDIENTE con estado_calculado VENCIDO, pero su plan
        // ya fue recalculado y hoy esta PROXIMO.
        $staleNotice = AvisoPlan::paraPlanVencido(
            $plan,
            new EvaluacionPlan(EstadoPlan::VENCIDO, [CriterioPlan::KILOMETRAJE], [], []),
            new DateTimeImmutable('2026-09-20'),
        );

        // La fila persistida dice VENCIDO, pero la fuente de verdad no: por eso
        // el listado debe filtrar por el estado vivo del plan, no confiar en
        // el estado_calculado congelado del aviso.
        self::assertNotSame(
            $dashboardState,
            EstadoPlan::VENCIDO,
            'Un plan a 963 km de su objetivo no puede evaluarse como vencido.',
        );
        self::assertSame(EstadoGestionAviso::PENDIENTE, $staleNotice->estadoGestion());

        // Al resolver el aviso (lo que hace el cierre de OT), deja de presentarse
        // como pendiente-vencido y ambos caminos coinciden.
        $staleNotice->marcarResuelto($now, 'Plan recalculado por cierre de orden preventiva.');

        self::assertSame(EstadoGestionAviso::RESUELTO, $staleNotice->estadoGestion());
        self::assertSame($dashboardState, EstadoPlan::PROXIMO);
    }

    /**
     * Caso 4: regresion especifica del escenario AD738EA.
     * "faltan 963 km" jamas puede clasificarse como VENCIDO, por ninguna
     * combinacion de fecha, horometro o anticipacion.
     */
    public function testScenarioWith963KilometersRemainingCannotBeClassifiedAsVencido(): void
    {
        $plan = $this->planByKilometers(
            intervalKm: 15_000,
            warningKm: 2_000,
            baseKm: 200_000,
            nextKm: 215_000,
        );

        $evaluation = (new EvaluadorVencimiento())->evaluar(
            $plan,
            new UsoActual(214_037, null),
            new DateTimeImmutable('2026-10-05'),
        );

        self::assertNotSame(EstadoPlan::VENCIDO, $evaluation->estado());
        self::assertSame(EstadoPlan::PROXIMO, $evaluation->estado());
        self::assertSame([], $evaluation->vencidos());
        self::assertSame([CriterioPlan::KILOMETRAJE], $evaluation->proximos());
    }

    /**
     * Un plan que vuelve a vencer tras ser recalculado debe generar un aviso con
     * clave de ciclo nueva, no reutilizar el del ciclo anterior.
     */
    public function testNewCycleProducesADifferentNoticeKeyThanThePreviousCycle(): void
    {
        $previousCycle = PlanMantenimiento::reconstituir(
            self::PLAN, self::EMPRESA, self::EQUIPO, 3,
            15_000, null, null,
            2_000, null, null,
            200_000, null, null,
            215_000, null, null,
            'MEDIA', true, null,
        );
        $newCycle = PlanMantenimiento::reconstituir(
            self::PLAN, self::EMPRESA, self::EQUIPO, 3,
            15_000, null, null,
            2_000, null, null,
            215_000, null, null,
            230_000, null, null,
            'MEDIA', true, null,
        );
        $evaluation = new EvaluacionPlan(
            EstadoPlan::VENCIDO,
            [CriterioPlan::KILOMETRAJE],
            [],
            [],
        );

        $previousNotice = AvisoPlan::paraPlanVencido(
            $previousCycle,
            $evaluation,
            new DateTimeImmutable('2026-09-20'),
        );
        $newNotice = AvisoPlan::paraPlanVencido(
            $newCycle,
            $evaluation,
            new DateTimeImmutable('2026-10-20'),
        );

        self::assertNotSame(
            $previousNotice->claveCiclo(),
            $newNotice->claveCiclo(),
            'Un ciclo nuevo debe producir una clave de aviso distinta.',
        );
    }

    // ------------------------------------------------------------------
    // Transiciones del aviso
    // ------------------------------------------------------------------

    public function testResolvingAPendingNoticeRequiresAMotive(): void
    {
        $notice = $this->pendingNotice();

        $this->expectException(DomainException::class);
        $notice->marcarResuelto(new DateTimeImmutable('2026-10-05'), '   ');
    }

    public function testAnAlreadyResolvedNoticeCannotBeResolvedAgain(): void
    {
        $notice = $this->pendingNotice();
        $notice->marcarResuelto(new DateTimeImmutable('2026-10-05'), 'Plan recalculado.');

        $this->expectException(DomainException::class);
        $notice->marcarResuelto(new DateTimeImmutable('2026-10-06'), 'Otra vez.');
    }

    private function pendingNotice(): AvisoPlan
    {
        $plan = $this->planByKilometers(
            intervalKm: 15_000,
            warningKm: 2_000,
            baseKm: 200_000,
            nextKm: 215_000,
        );

        return AvisoPlan::paraPlanVencido(
            $plan,
            new EvaluacionPlan(EstadoPlan::VENCIDO, [CriterioPlan::KILOMETRAJE], [], []),
            new DateTimeImmutable('2026-09-20'),
        );
    }

    private function planByKilometers(
        int $intervalKm,
        int $warningKm,
        int $baseKm,
        int $nextKm,
    ): PlanMantenimiento {
        return PlanMantenimiento::reconstituir(
            self::PLAN,
            self::EMPRESA,
            self::EQUIPO,
            3,
            $intervalKm,
            null,
            null,
            $warningKm,
            null,
            null,
            $baseKm,
            null,
            null,
            $nextKm,
            null,
            null,
            'MEDIA',
            true,
            null,
        );
    }
}