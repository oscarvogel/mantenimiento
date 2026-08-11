<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\ListPreventivePlansHandler;
use App\Application\PreventiveMaintenance\Port\Clock;
use App\Application\PreventiveMaintenance\Port\PreventivePlanReadModel;
use App\Application\PreventiveMaintenance\PreventivePlanListItem;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\UsoActual;
use PHPUnit\Framework\TestCase;

final class ListPreventivePlansHandlerTest extends TestCase
{
    public function testListsFiltersAndPaginatesPlansWithoutEquipmentPermission(): void
    {
        $readModel = new FakePreventivePlanReadModel([
            $this->item(1, 10, 7, 'CAM-01', 9_900),
            $this->item(2, 11, 8, 'CAM-02', 10_000),
        ]);
        $handler = new ListPreventivePlansHandler($readModel, new EvaluadorVencimiento(), new FixedPlanListClock());
        $actor = new ActorContext(9, 5, false, false, ['Responsable'], ['planes.ver'], [7]);

        $result = $handler->execute($actor, ['branch_id' => 7, 'state' => 'PROXIMO'], 1, 1);

        self::assertSame(1, $result->total);
        self::assertSame('CAM-01', $result->items[0]['equipment_code']);
        self::assertSame('PROXIMO', $result->items[0]['state']);
        self::assertSame([7], $readModel->receivedScope);
    }

    public function testRejectsAnUnauthorizedBranchBeforeReturningPlans(): void
    {
        $handler = new ListPreventivePlansHandler(new FakePreventivePlanReadModel([]), new EvaluadorVencimiento(), new FixedPlanListClock());
        $actor = new ActorContext(9, 5, false, false, [], ['planes.ver'], [7]);

        $this->expectException(DomainException::class);
        $handler->execute($actor, ['branch_id' => 8]);
    }

    public function testPaginatesAfterApplyingAuthorizedScope(): void
    {
        $handler = new ListPreventivePlansHandler(new FakePreventivePlanReadModel([
            $this->item(1, 10, 7, 'CAM-01', 9_000),
            $this->item(2, 11, 7, 'CAM-02', 9_000),
            $this->item(3, 12, 7, 'CAM-03', 9_000),
            $this->item(4, 13, 7, 'CAM-04', 9_000),
            $this->item(5, 14, 7, 'CAM-05', 9_000),
            $this->item(6, 15, 7, 'CAM-06', 9_000),
            $this->item(7, 16, 8, 'CAM-07', 9_000),
        ]), new EvaluadorVencimiento(), new FixedPlanListClock());
        $actor = new ActorContext(9, 5, false, false, [], ['planes.ver'], [7]);

        $result = $handler->execute($actor, [], 2, 5);

        self::assertSame(6, $result->total);
        self::assertSame(2, $result->totalPages());
        self::assertSame('CAM-06', $result->items[0]['equipment_code']);
    }

    public function testRejectsActorWithoutReadPermission(): void
    {
        $handler = new ListPreventivePlansHandler(new FakePreventivePlanReadModel([]), new EvaluadorVencimiento(), new FixedPlanListClock());
        $actor = new ActorContext(9, 5, false, true, [], ['equipos.ver'], []);

        $this->expectException(DomainException::class);
        $handler->execute($actor, []);
    }

    public function testUsesTenAsDefaultAndNormalizesValuesOutsideStrictWhitelist(): void
    {
        $handler = new ListPreventivePlansHandler(new FakePreventivePlanReadModel([]), new EvaluadorVencimiento(), new FixedPlanListClock());
        $actor = new ActorContext(9, 5, false, true, [], ['planes.ver'], []);

        self::assertSame(10, $handler->execute($actor, [])->perPage);
        self::assertSame(10, $handler->execute($actor, [], 1, 999)->perPage);
        self::assertSame(25, $handler->execute($actor, [], 1, 25)->perPage);
    }

    private function item(int $planId, int $equipmentId, int $branchId, string $code, int $currentKm): PreventivePlanListItem
    {
        return new PreventivePlanListItem(
            PlanMantenimiento::reconstituir(
                $planId, 5, $equipmentId, 3,
                1_000, null, null,
                200, null, null,
                9_000, null, null,
                10_000, null, null,
                'MEDIA', true, null,
            ),
            new UsoActual($currentKm, null),
            $code,
            null,
            $branchId,
            'S' . $branchId,
            'Sucursal ' . $branchId,
            'Camión',
            'Service motor',
        );
    }
}

final class FakePreventivePlanReadModel implements PreventivePlanReadModel
{
    /** @var list<int>|null */
    public ?array $receivedScope = null;

    /** @param list<PreventivePlanListItem> $items */
    public function __construct(private readonly array $items)
    {
    }

    public function listActive(int $companyId, ?array $branchIds): array
    {
        $this->receivedScope = $branchIds;
        return array_values(array_filter($this->items, static fn (PreventivePlanListItem $item): bool => $branchIds === null || in_array($item->branchId, $branchIds, true)));
    }

    public function listActiveEquipment(int $companyId, ?array $branchIds): array { return []; }
    public function listActiveServiceTypes(): array { return []; }
    public function listActiveBranches(int $companyId, ?array $branchIds): array { return []; }
    public function listTemplateDefaults(int $companyId): array { return []; }
}

final readonly class FixedPlanListClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-10');
    }
}
