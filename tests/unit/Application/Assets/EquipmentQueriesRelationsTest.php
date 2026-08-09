<?php

declare(strict_types=1);

use App\Application\Assets\CreateEquipmentRelationCommand;
use App\Application\Assets\CreateEquipmentRelationHandler;
use App\Application\Assets\EquipmentListQuery;
use App\Application\Assets\FinishEquipmentRelationCommand;
use App\Application\Assets\FinishEquipmentRelationHandler;
use App\Application\Assets\GetEquipmentQrPayload;
use App\Application\Assets\ListEquipment;
use App\Application\Assets\RenderEquipmentQr;
use App\Application\Assets\Port\AssetUnitOfWork;
use App\Application\Assets\Port\EquipmentListReadModel;
use App\Application\Assets\Port\EquipmentQrReadModel;
use App\Application\Assets\Port\EquipmentQrRenderer;
use App\Application\Assets\Port\EquipmentRelationRepository;
use App\Application\Identity\ActorContext;
use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentRelation;
use App\Domain\Assets\EquipmentType;
use PHPUnit\Framework\TestCase;

final class EquipmentQueriesRelationsTest extends TestCase
{
    public function testListPropagatesTenantBranchScopeFiltersAndPagination(): void
    {
        $read = new Phase2CEquipmentListFake();
        $result = (new ListEquipment($read))->execute(
            $this->actor(['equipos.ver'], [7, 8]),
            new EquipmentListQuery(' abc ', 2, 3, 7, Equipment::ACTIVE, 2, 10),
        );

        self::assertSame(5, $read->companyId);
        self::assertSame([7, 8], $read->branchIds);
        self::assertSame('abc', $read->query);
        self::assertSame(2, $result['page']);
        self::assertSame(3, $result['totalPages']);
    }

    public function testListRejectsBranchOutsideActorScopeBeforeRead(): void
    {
        $read = new Phase2CEquipmentListFake();
        try {
            (new ListEquipment($read))->execute($this->actor(['equipos.ver'], [7]), new EquipmentListQuery(branchId: 8));
            self::fail('La sucursal ajena debió rechazarse.');
        } catch (DomainException) {
            self::assertNull($read->companyId);
        }
    }

    public function testCreatesRelationOnlyWhenBothEquipmentAreScopedAndCompatible(): void
    {
        $repository = new Phase2CRelationRepositoryFake($this->equipment(10), $this->equipment(11));
        $handler = new CreateEquipmentRelationHandler($repository, new Phase2CUnitOfWorkFake());
        $result = $handler->execute(
            $this->actor(['equipos.editar'], [7]),
            new CreateEquipmentRelationCommand(10, 11, EquipmentRelation::TRACTOR_TRAILER, new DateTimeImmutable('2026-08-08'), 'Asignación'),
        );

        self::assertSame(301, $result->relationId);
        self::assertSame([7], $repository->lastScope);
        self::assertTrue($result->active);
    }

    public function testRejectsSecondActiveIncompatibleRelation(): void
    {
        $repository = new Phase2CRelationRepositoryFake($this->equipment(10), $this->equipment(11));
        $repository->incompatible = true;
        $handler = new CreateEquipmentRelationHandler($repository, new Phase2CUnitOfWorkFake());

        $this->expectException(DomainException::class);
        $handler->execute(
            $this->actor(['equipos.editar'], [7]),
            new CreateEquipmentRelationCommand(10, 11, EquipmentRelation::TRACTOR_TRAILER, new DateTimeImmutable('2026-08-08')),
        );
    }

    public function testFinishesRelationshipWithoutDeletingIt(): void
    {
        $repository = new Phase2CRelationRepositoryFake($this->equipment(10), $this->equipment(11));
        $repository->relation = EquipmentRelation::reconstitute(
            20, 5, 10, 11, EquipmentRelation::OTHER, new DateTimeImmutable('2026-08-08'), null, 3, null, null, null,
        );
        $result = (new FinishEquipmentRelationHandler($repository, new Phase2CUnitOfWorkFake()))->execute(
            $this->actor(['equipos.editar'], [7]),
            new FinishEquipmentRelationCommand(20, new DateTimeImmutable('2026-08-09'), 'Desvinculado'),
        );

        self::assertFalse($result->active);
        self::assertTrue($repository->finished);
        self::assertSame('2026-08-09', $repository->relation?->endedAt()?->format('Y-m-d'));
    }

    public function testQrReturnsCanonicalAuthenticatedDetailReference(): void
    {
        $read = new class implements EquipmentQrReadModel {
            public ?array $scope = null;
            public function findScoped(int $companyId, int $equipmentId, ?array $branchIds): ?array
            {
                $this->scope = $branchIds;
                return ['id' => $equipmentId, 'codigo' => 'TR-01'];
            }
        };
        $payload = (new GetEquipmentQrPayload($read))->execute($this->actor(['equipos.ver'], [7]), 10);

        self::assertSame('/mantenimiento/equipos/10', $payload->relativePath);
        self::assertSame('mantenimiento:equipo:10', $payload->canonicalReference());
        self::assertSame([7], $read->scope);
    }

    public function testRenderedQrUsesAbsoluteEquipmentUrlWithoutCouplingApplicationToLibrary(): void
    {
        $read = new class implements EquipmentQrReadModel {
            public function findScoped(int $companyId, int $equipmentId, ?array $branchIds): ?array
            {
                return ['id' => $equipmentId, 'codigo' => 'TR-01'];
            }
        };
        $renderer = new class implements EquipmentQrRenderer {
            public string $value = '';
            public function renderSvg(string $value): string
            {
                $this->value = $value;
                return '<svg />';
            }
        };

        $result = (new RenderEquipmentQr(new GetEquipmentQrPayload($read), $renderer))->execute(
            $this->actor(['equipos.ver'], [7]),
            10,
            'https://example.test/',
        );

        self::assertSame('https://example.test/mantenimiento/equipos/10', $result->targetUrl);
        self::assertSame($result->targetUrl, $renderer->value);
        self::assertSame('<svg />', $result->svg);
    }

    /** @param list<string> $permissions @param list<int> $branches */
    private function actor(array $permissions, array $branches): ActorContext
    {
        return new ActorContext(3, 5, false, false, ['Responsable'], $permissions, $branches);
    }

    private function equipment(int $id): Equipment
    {
        return Equipment::reconstitute(
            $id, 5, 7, new EquipmentType(1, 'Equipo', true, true), 'EQ-' . $id, null, Equipment::ACTIVE,
            new DateTimeImmutable('2026-08-01'), null, null, null, null,
        );
    }
}

final class Phase2CEquipmentListFake implements EquipmentListReadModel
{
    public ?int $companyId = null;
    public ?array $branchIds = null;
    public ?string $query = null;
    public function search(int $companyId, ?array $branchIds, ?string $query, ?int $typeId, ?int $brandId, ?int $branchId, ?string $status, int $page, int $perPage): array
    {
        $this->companyId = $companyId; $this->branchIds = $branchIds; $this->query = $query;
        return ['items' => [['id' => 1]], 'total' => 25];
    }
}

final class Phase2CRelationRepositoryFake implements EquipmentRelationRepository
{
    public bool $incompatible = false;
    public bool $finished = false;
    public ?EquipmentRelation $relation = null;
    public ?array $lastScope = null;
    private int $calls = 0;
    public function __construct(private ?Equipment $principal, private ?Equipment $related) {}
    public function findEquipmentForUpdate(int $companyId, int $equipmentId, ?array $branchIds): ?Equipment
    {
        $this->lastScope = $branchIds;
        return $this->calls++ === 0 ? $this->principal : $this->related;
    }
    public function hasActiveIncompatibleRelation(int $companyId, int $relatedEquipmentId, string $type): bool { return $this->incompatible; }
    public function add(EquipmentRelation $relation): int { $this->relation = $relation; return 301; }
    public function findRelationForUpdate(int $companyId, int $relationId, ?array $branchIds): ?EquipmentRelation { $this->lastScope = $branchIds; return $this->relation; }
    public function finish(EquipmentRelation $relation): void { $this->relation = $relation; $this->finished = true; }
}

final class Phase2CUnitOfWorkFake implements AssetUnitOfWork
{
    public function transactional(callable $operation): mixed { return $operation(); }
}
