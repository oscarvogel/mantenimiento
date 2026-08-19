<?php

declare(strict_types=1);

use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Application\PreventiveMaintenance\Port\ServiceTypeGateway;
use App\Application\PreventiveMaintenance\RecalcularPlanTrasCierre;
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

        (new RecalcularPlanTrasCierre($plans, $services))->execute(
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
        ))->execute(5, 12, null, new DateTimeImmutable('2026-08-18'), 120_000, null, 9);
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
