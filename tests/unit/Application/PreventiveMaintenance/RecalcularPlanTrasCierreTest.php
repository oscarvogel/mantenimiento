<?php

declare(strict_types=1);

use App\Application\PreventiveMaintenance\Port\MaintenanceNoticeRepository;
use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Application\PreventiveMaintenance\Port\ServiceTypeGateway;
use App\Application\PreventiveMaintenance\RecalcularPlanTrasCierre;
use App\Domain\PreventiveMaintenance\AvisoPlan;
use App\Domain\PreventiveMaintenance\EvaluacionPlan;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use PHPUnit\Framework\TestCase;

final class RecalcularPlanTrasCierreTest extends TestCase
{
    public function testClosingUsesCurrentServiceDefinitionInsteadOfLegacyPlanFrequency(): void
    {
        $legacyPlan = PlanMantenimiento::reconstituir(
            12,
            5,
            20,
            3,
            10_000,
            null,
            null,
            1_000,
            null,
            null,
            90_000,
            null,
            null,
            100_000,
            null,
            null,
            'MEDIA',
            true,
            'Asignación existente',
        );
        $plans = new ClosingPlanRepository($legacyPlan);
        $services = new ClosingServiceGateway([
            'id' => 3,
            'intervalKm' => 20_000,
            'intervalHoursTenths' => null,
            'intervalDays' => null,
            'warningKm' => 2_000,
            'warningHoursTenths' => null,
            'warningDays' => null,
            'priority' => 'ALTA',
        ]);

        (new RecalcularPlanTrasCierre($plans, $services, new ClosingNoticeRepository()))->execute(
            5,
            12,
            null,
            new DateTimeImmutable('2026-08-18'),
            120_000,
            null,
            9,
        );

        self::assertNotNull($plans->saved);
        self::assertSame(20_000, $plans->saved->intervaloKm());
        self::assertSame(2_000, $plans->saved->anticipacionKm());
        self::assertSame('ALTA', $plans->saved->prioridad());
        self::assertSame(120_000, $plans->saved->baseKm());
        self::assertSame(140_000, $plans->saved->proximoKm());
        self::assertSame('Asignación existente', $plans->saved->observaciones());
        self::assertSame(9, $plans->actorUserId);
    }

    public function testClosingRejectsInactiveOrMissingServiceDefinition(): void
    {
        $plan = PlanMantenimiento::reconstituir(
            12, 5, 20, 3,
            10_000, null, null,
            1_000, null, null,
            90_000, null, null,
            100_000, null, null,
            'MEDIA', true, null,
        );

        $this->expectException(DomainException::class);
        (new RecalcularPlanTrasCierre(
            new ClosingPlanRepository($plan),
            new ClosingServiceGateway(null),
            new ClosingNoticeRepository(),
        ))->execute(5, 12, null, new DateTimeImmutable('2026-08-18'), 120_000, null, 9);
    }

    /**
     * El cierre abre un ciclo nuevo del plan, asi que los avisos PENDIENTE de
     * ciclos anteriores deben quedar RESUELTO. Si no, la pantalla "Atencion
     * requerida" los sigue mostrando como vencidos aunque el plan recien
     * recalculado ya este al dia o proximo.
     */
    public function testClosingResolvesPendingNoticesOfPreviousCycle(): void
    {
        $plan = PlanMantenimiento::reconstituir(
            12, 5, 20, 3,
            10_000, null, null,
            1_000, null, null,
            90_000, null, null,
            100_000, null, null,
            'MEDIA', true, null,
        );
        $notice = AvisoPlan::paraPlanVencido(
            $plan,
            new EvaluacionPlan(
                \App\Domain\PreventiveMaintenance\EstadoPlan::VENCIDO,
                [\App\Domain\PreventiveMaintenance\CriterioPlan::KILOMETRAJE],
                [],
                [],
            ),
            new DateTimeImmutable('2026-08-01'),
        );
        $notices = new ClosingNoticeRepository([$notice]);
        $completedAt = new DateTimeImmutable('2026-08-18');

        (new RecalcularPlanTrasCierre(
            new ClosingPlanRepository($plan),
            new ClosingServiceGateway([
                'id' => 3,
                'intervalKm' => 10_000,
                'intervalHoursTenths' => null,
                'intervalDays' => null,
                'warningKm' => 1_000,
                'warningHoursTenths' => null,
                'warningDays' => null,
                'priority' => 'MEDIA',
            ]),
            $notices,
        ))->execute(5, 12, null, $completedAt, 120_000, null, 9);

        self::assertCount(1, $notices->saved);
        $resolved = $notices->saved[0];
        self::assertSame(
            \App\Domain\PreventiveMaintenance\EstadoGestionAviso::RESUELTO,
            $resolved->estadoGestion(),
        );
        self::assertSame($completedAt, $resolved->fechaResolucion());
        self::assertNotNull($resolved->motivoResolucion());
    }

    /** Tras el cierre el plan queda proximo, nunca vencido: el aviso viejo no debe sobrevivir. */
    public function testClosingLeavesNoPendingNoticeWhenPlanIsStillOverdueInNewCycle(): void
    {
        $plan = PlanMantenimiento::reconstituir(
            12, 5, 20, 3,
            10_000, null, null,
            1_000, null, null,
            90_000, null, null,
            100_000, null, null,
            'MEDIA', true, null,
        );
        $notice = AvisoPlan::paraPlanVencido(
            $plan,
            new EvaluacionPlan(
                \App\Domain\PreventiveMaintenance\EstadoPlan::VENCIDO,
                [\App\Domain\PreventiveMaintenance\CriterioPlan::KILOMETRAJE],
                [],
                [],
            ),
            new DateTimeImmutable('2026-08-01'),
        );
        $notices = new ClosingNoticeRepository([$notice]);
        $plans = new ClosingPlanRepository($plan);

        (new RecalcularPlanTrasCierre(
            $plans,
            new ClosingServiceGateway([
                'id' => 3,
                'intervalKm' => 10_000,
                'intervalHoursTenths' => null,
                'intervalDays' => null,
                'warningKm' => 1_000,
                'warningHoursTenths' => null,
                'warningDays' => null,
                'priority' => 'MEDIA',
            ]),
            $notices,
        ))->execute(5, 12, null, new DateTimeImmutable('2026-08-18'), 120_000, null, 9);

        self::assertSame(130_000, $plans->saved->proximoKm());
        self::assertCount(0, $notices->allPending(12));
    }
}

final class ClosingPlanRepository implements PlanMantenimientoRepository
{
    public ?PlanMantenimiento $saved = null;
    public ?int $actorUserId = null;

    public function __construct(private readonly ?PlanMantenimiento $found)
    {
    }

    public function findScoped(int $companyId, int $planId, ?array $branchIds, bool $forUpdate = false): ?PlanMantenimiento
    {
        return $this->found;
    }

    public function existsActive(int $companyId, int $equipmentId, int $serviceTypeId, ?array $branchIds): bool
    {
        return false;
    }

    public function listActiveScoped(int $companyId, ?array $branchIds): array
    {
        return [];
    }

    public function save(PlanMantenimiento $plan, int $actorUserId): int
    {
        $this->saved = $plan;
        $this->actorUserId = $actorUserId;
        return (int) $plan->id();
    }
}

final readonly class ClosingServiceGateway implements ServiceTypeGateway
{
    public function __construct(private ?array $definition)
    {
    }

    public function findActiveDefinition(int $companyId, int $serviceTypeId): ?array
    {
        return $this->definition;
    }
}

final class ClosingNoticeRepository implements MaintenanceNoticeRepository
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

    public function pendingForCycle(int $companyId, int $planId, string $claveCiclo): array
    {
        return array_values(array_filter(
            $this->notices,
            static fn (AvisoPlan $notice): bool => $notice->planId() === $planId
                && $notice->claveCiclo() === $claveCiclo
                && $notice->estadoGestion() === \App\Domain\PreventiveMaintenance\EstadoGestionAviso::PENDIENTE,
        ));
    }

    /** @return list<AvisoPlan> */
    public function allPending(int $planId): array
    {
        return array_values(array_filter(
            $this->notices,
            static fn (AvisoPlan $notice): bool => $notice->planId() === $planId
                && $notice->estadoGestion() === \App\Domain\PreventiveMaintenance\EstadoGestionAviso::PENDIENTE,
        ));
    }

    public function save(AvisoPlan $notice, ?int $actorUserId): int
    {
        $this->saved[] = $notice;

        return $notice->id() ?? 0;
    }
}
