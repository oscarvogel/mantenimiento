<?php

declare(strict_types=1);

namespace Tests\Unit\Application\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\Port\MaintenanceNoticeRepository;
use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Application\PreventiveMaintenance\Port\ServiceTypeGateway;
use App\Application\PreventiveMaintenance\RecalcularPlanTrasCierre;
use App\Domain\PreventiveMaintenance\AvisoPlan;
use App\Domain\PreventiveMaintenance\CriterioPlan;
use App\Domain\PreventiveMaintenance\EstadoGestionAviso;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use App\Domain\PreventiveMaintenance\EvaluacionPlan;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\UsoActual;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Ciclo de vida completo del aviso preventivo, verificado contra el evaluador
 * real y la transicion real de dominio:
 *
 *  1. el plan vence            => EvaluadorVencimiento = VENCIDO
 *  2. se crea aviso PENDIENTE  => MaterializarAvisoVencido (clave de ciclo)
 *  3. se genera/gestiona la OT
 *  4. se cierra la OT
 *  5. RecalcularPlanTrasCierre abre el ciclo nuevo
 *  6. el aviso del ciclo viejo pasa a RESUELTO
 *  7. si el ciclo nuevo vuelve a vencer, el aviso nuevo tiene otra clave
 */
final class CicloVidaAvisoPreventivoTest extends TestCase
{
    private const EMPRESA = 7;
    private const PLAN = 44;
    private const EQUIPO = 20;
    private const SERVICIO = 3;

    /** Paso 1: el plan vence cuando el kilometraje alcanza el objetivo. */
    public function testElPlanVenceAlAlcanzarElObjetivo(): void
    {
        $plan = $this->planWithBase(200_000, nextKm: 215_000);

        $evaluation = $this->evaluator()->evaluar(
            $plan,
            new UsoActual(215_000, null),
            new DateTimeImmutable('2026-10-05'),
        );

        self::assertSame(EstadoPlan::VENCIDO, $evaluation->estado());
        self::assertSame([CriterioPlan::KILOMETRAJE], $evaluation->vencidos());
    }

    /** Paso 2: se materializa un aviso PENDIENTE con clave de ciclo. */
    public function testVencimientoMaterializaUnAvisoPendiente(): void
    {
        $plan = $this->planWithBase(200_000, nextKm: 215_000);
        $notice = $this->noticeFor($plan, '2026-10-05');

        self::assertSame(EstadoGestionAviso::PENDIENTE, $notice->estadoGestion());
        self::assertSame(['KILOMETRAJE'], $notice->criteriosDisparadores());
        self::assertNotSame('', $notice->claveCiclo());
    }

    /** Paso 3: convertir la OT en orden deja de exhibir el aviso. */
    public function testGenerarLaOrdenConvierteElAvisoYLoSacaDePendientes(): void
    {
        $notice = $this->noticeFor($this->planWithBase(200_000, nextKm: 215_000), '2026-10-05');

        $notice->marcarConvertido(new DateTimeImmutable('2026-10-06'));

        self::assertSame(EstadoGestionAviso::CONVERTIDO, $notice->estadoGestion());
    }

    /**
     * Pasos 4 a 6: al cerrar la OT se recalcula el plan y el aviso del ciclo
     * anterior queda RESUELTO con motivo.
     */
    public function testCerrarLaOrdenResuelveElAvisoDelCicloAnterior(): void
    {
        $plan = $this->planWithBase(200_000, nextKm: 215_000);
        $notice = $this->noticeFor($plan, '2026-10-05');
        $notices = new InMemoryNoticeRepository([$notice]);
        $plans = new InMemoryPlanRepository($plan);
        $completedAt = new DateTimeImmutable('2026-10-07');

        $result = (new RecalcularPlanTrasCierre($plans, $this->serviceGateway(), $notices))
            ->execute(self::EMPRESA, self::PLAN, null, $completedAt, 215_000, null, 9);

        // Paso 5: el plan arranca el ciclo nuevo con la base de la OT cerrada.
        self::assertSame(230_000, $result['proximo_km']);

        // Paso 6: el aviso viejo ya no requiere atencion.
        self::assertSame([], $notices->pendingForPlan(self::EMPRESA, self::PLAN));
        self::assertCount(1, $notices->saved);
        self::assertSame(EstadoGestionAviso::RESUELTO, $notice->estadoGestion());
        self::assertSame($completedAt, $notice->fechaResolucion());
        self::assertStringContainsString('recalculado', (string) $notice->motivoResolucion());

        // Y el plan recalculado ya no figura como vencido.
        $evaluation = $this->evaluator()->evaluar(
            $plans->saved,
            new UsoActual(215_000, null),
            new DateTimeImmutable('2026-10-08'),
        );
        self::assertNotSame(EstadoPlan::VENCIDO, $evaluation->estado());
    }

    /**
     * Paso 7: cuando el ciclo nuevo vuelve a vencer se crea un aviso nuevo, con
     * otra clave. El aviso historico no se recicla.
     */
    public function testElCicloNuevoGeneraUnAvisoNuevoConOtraClaveDeCiclo(): void
    {
        $previousCycle = $this->planWithBase(200_000, nextKm: 215_000);
        $previousNotice = $this->noticeFor($previousCycle, '2026-10-05');

        $plans = new InMemoryPlanRepository($previousCycle);
        $notices = new InMemoryNoticeRepository([$previousNotice]);
        (new RecalcularPlanTrasCierre($plans, $this->serviceGateway(), $notices))
            ->execute(self::EMPRESA, self::PLAN, null, new DateTimeImmutable('2026-10-07'), 215_000, null, 9);

        // El ciclo nuevo vuelve a vencerse con otra salida.
        $newCycle = $this->planWithBase(215_000, nextKm: 230_000);
        $newNotice = $this->noticeFor($newCycle, '2027-04-10');

        self::assertNotSame(
            $previousNotice->claveCiclo(),
            $newNotice->claveCiclo(),
            'El aviso del ciclo nuevo no puede reutilizar la clave del anterior.',
        );
        self::assertSame(EstadoGestionAviso::PENDIENTE, $newNotice->estadoGestion());
        self::assertSame(EstadoGestionAviso::RESUELTO, $previousNotice->estadoGestion());
    }

    /** Un ciclo nuevo vencido se materializa sin interferir con el historico resuelto. */
    public function testDetectarUnNuevoVencimientoCreaUnAvisoDistintoAlResuelto(): void
    {
        $resolved = $this->noticeFor($this->planWithBase(200_000, nextKm: 215_000), '2026-10-05');
        $resolved->marcarResuelto(new DateTimeImmutable('2026-10-07'), 'Plan recalculado por cierre.');

        $newPlan = $this->planWithBase(215_000, nextKm: 230_000);
        $newNotice = $this->noticeFor($newPlan, '2027-04-10');

        self::assertSame(EstadoGestionAviso::RESUELTO, $resolved->estadoGestion());
        self::assertSame(EstadoGestionAviso::PENDIENTE, $newNotice->estadoGestion());
        self::assertNotSame($resolved->claveCiclo(), $newNotice->claveCiclo());
    }

    // ------------------------------------------------------------------

    private function evaluator(): EvaluadorVencimiento
    {
        return new EvaluadorVencimiento();
    }

    private function noticeFor(PlanMantenimiento $plan, string $detectedAt): AvisoPlan
    {
        return AvisoPlan::paraPlanVencido(
            $plan,
            new EvaluacionPlan(EstadoPlan::VENCIDO, [CriterioPlan::KILOMETRAJE], [], []),
            new DateTimeImmutable($detectedAt),
        );
    }

    private function planWithBase(int $baseKm, int $nextKm): PlanMantenimiento
    {
        return PlanMantenimiento::reconstituir(
            self::PLAN,
            self::EMPRESA,
            self::EQUIPO,
            self::SERVICIO,
            15_000,
            null,
            null,
            2_000,
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

    private function serviceGateway(): ServiceTypeGateway
    {
        return new LifecycleServiceGateway([
            'id' => self::SERVICIO,
            'intervalKm' => 15_000,
            'intervalHoursTenths' => null,
            'intervalDays' => null,
            'warningKm' => 2_000,
            'warningHoursTenths' => null,
            'warningDays' => null,
            'priority' => 'MEDIA',
        ]);
    }
}

final readonly class LifecycleServiceGateway implements ServiceTypeGateway
{
    public function __construct(private array $definition)
    {
    }

    public function findActiveDefinition(int $companyId, int $serviceTypeId): ?array
    {
        return $this->definition;
    }
}

final class InMemoryPlanRepository implements PlanMantenimientoRepository
{
    public ?PlanMantenimiento $saved = null;

    public function __construct(private PlanMantenimiento $plan)
    {
    }

    public function findScoped(int $companyId, int $planId, ?array $branchIds, bool $forUpdate = false): ?PlanMantenimiento
    {
        if ($companyId !== $this->plan->empresaId() || $planId !== $this->plan->id()) {
            return null;
        }

        return $this->plan;
    }

    public function save(PlanMantenimiento $plan, int $actorUserId): int
    {
        $this->saved = $plan;

        return (int) $plan->id();
    }

    public function existsActive(int $companyId, int $equipmentId, int $serviceTypeId, ?array $branchIds): bool
    {
        return true;
    }

    /** @return list<PlanMantenimiento> */
    public function listActiveScoped(int $companyId, ?array $branchIds): array
    {
        return [$this->saved ?? $this->plan];
    }
}

final class InMemoryNoticeRepository implements MaintenanceNoticeRepository
{
    /** @var list<AvisoPlan> */
    private array $notices;

    /** @var list<AvisoPlan> */
    public array $saved = [];

    /** @param list<AvisoPlan> $notices */
    public function __construct(array $notices = [])
    {
        $this->notices = $notices;
    }

    public function findByCycleKey(int $companyId, int $planId, string $cycleKey): ?AvisoPlan
    {
        foreach ($this->notices as $notice) {
            if ($notice->planId() === $planId && $notice->claveCiclo() === $cycleKey) {
                return $notice;
            }
        }

        return null;
    }

    public function findScoped(int $companyId, int $noticeId, ?array $branchIds, bool $forUpdate = false): ?AvisoPlan
    {
        return null;
    }

    public function pendingForPlan(int $companyId, int $planId): array
    {
        return array_values(array_filter(
            $this->notices,
            static fn (AvisoPlan $notice): bool => $notice->planId() === $planId
                && $notice->estadoGestion() === EstadoGestionAviso::PENDIENTE,
        ));
    }

    public function save(AvisoPlan $notice, ?int $actorUserId): int
    {
        $this->saved[] = $notice;

        return $notice->id() ?? 0;
    }
}