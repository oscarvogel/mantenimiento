<?php

declare(strict_types=1);

use App\Application\Assets\DecommissionEquipmentCommand;
use App\Application\Assets\DecommissionEquipmentHandler;
use App\Application\Assets\GetEquipmentDetails;
use App\Application\Assets\Port\AssetUnitOfWork;
use App\Application\Assets\Port\BranchScope;
use App\Application\Assets\Port\EquipmentLifecycleRepository;
use App\Application\Assets\Port\EquipmentReadModel;
use App\Application\Assets\Port\EquipmentWorkStatus;
use App\Application\Assets\Port\EquipmentRelationStatus;
use App\Application\Assets\TransferEquipmentCommand;
use App\Application\Assets\TransferEquipmentHandler;
use App\Application\Assets\UpdateEquipmentCommand;
use App\Application\Assets\UpdateEquipmentHandler;
use App\Application\Identity\ActorContext;
use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentType;
use PHPUnit\Framework\TestCase;

final class EquipmentLifecycleHandlersTest extends TestCase
{
    public function testUpdatesProfileInsideCompanyAndBranchScope(): void
    {
        $repository = new LifecycleEquipmentRepositoryFake($this->equipment());
        $handler = new UpdateEquipmentHandler($repository, new ImmediateAssetUnitOfWork());

        $result = $handler->execute(
            $this->actor(['equipos.editar'], [7]),
            new UpdateEquipmentCommand(30, ' eq-02 ', ' aa 100 aa ', ' Actualizada '),
        );

        self::assertSame([7], $repository->requestedBranches);
        self::assertTrue($repository->profileUpdated);
        self::assertSame('EQ-02', $result->code);
        self::assertSame(7, $result->branchId);
    }

    public function testRejectsDuplicateCodeWithoutWritingProfile(): void
    {
        $repository = new LifecycleEquipmentRepositoryFake($this->equipment());
        $repository->duplicateCode = true;
        $handler = new UpdateEquipmentHandler($repository, new ImmediateAssetUnitOfWork());

        try {
            $handler->execute(
                $this->actor(['equipos.editar'], [7]),
                new UpdateEquipmentCommand(30, 'EQ-EXISTENTE', null, null),
            );
            self::fail('El código duplicado debió rechazarse.');
        } catch (DomainException) {
            self::assertFalse($repository->profileUpdated);
        }
    }

    public function testAcceptsIdempotentProfileUpdate(): void
    {
        $repository = new LifecycleEquipmentRepositoryFake($this->equipment());
        $handler = new UpdateEquipmentHandler($repository, new ImmediateAssetUnitOfWork());

        $result = $handler->execute(
            $this->actor(['equipos.editar'], [7]),
            new UpdateEquipmentCommand(30, 'EQ-01', null, null),
        );

        self::assertTrue($repository->profileUpdated);
        self::assertSame('EQ-01', $result->code);
    }

    public function testRejectsDecommissionWhenEquipmentHasOpenWorkOrder(): void
    {
        $repository = new LifecycleEquipmentRepositoryFake($this->equipment());
        $handler = new DecommissionEquipmentHandler(
            $repository,
            new EquipmentWorkStatusFake(true),
            new ImmediateAssetUnitOfWork(),
        );

        try {
            $handler->execute(
                $this->actor(['equipos.editar'], [7]),
                new DecommissionEquipmentCommand(30, new DateTimeImmutable('2026-08-10')),
            );
            self::fail('La baja con OT abierta debió rechazarse.');
        } catch (DomainException) {
            self::assertFalse($repository->decommissioned);
        }
    }

    public function testDecommissionsWithoutDeletingEquipment(): void
    {
        $repository = new LifecycleEquipmentRepositoryFake($this->equipment());
        $handler = new DecommissionEquipmentHandler(
            $repository,
            new EquipmentWorkStatusFake(false),
            new ImmediateAssetUnitOfWork(),
        );

        $result = $handler->execute(
            $this->actor(['equipos.editar'], [7]),
            new DecommissionEquipmentCommand(30, new DateTimeImmutable('2026-08-10')),
        );

        self::assertTrue($repository->decommissioned);
        self::assertSame(Equipment::INACTIVE, $result->status);
    }

    public function testRejectsDecommissionWhileEquipmentHasActiveRelation(): void
    {
        $repository = new LifecycleEquipmentRepositoryFake($this->equipment());
        $handler = new DecommissionEquipmentHandler(
            $repository,
            new EquipmentWorkStatusFake(false),
            new ImmediateAssetUnitOfWork(),
            new EquipmentRelationStatusFake(true),
        );

        try {
            $handler->execute(
                $this->actor(['equipos.editar'], [7]),
                new DecommissionEquipmentCommand(30, new DateTimeImmutable('2026-08-10')),
            );
            self::fail('La baja con una relación activa debió rechazarse.');
        } catch (DomainException) {
            self::assertFalse($repository->decommissioned);
        }
    }

    public function testTransfersOnlyToActiveAuthorizedBranchAndRecordsHistoryData(): void
    {
        $repository = new LifecycleEquipmentRepositoryFake($this->equipment());
        $handler = new TransferEquipmentHandler(
            $repository,
            new LifecycleBranchScopeFake(true),
            new ImmediateAssetUnitOfWork(),
        );

        $result = $handler->execute(
            $this->actor(['equipos.editar'], [7, 8]),
            new TransferEquipmentCommand(30, 8, new DateTimeImmutable('2026-08-10 09:30:00'), 'Cambio de base operativa'),
        );

        self::assertSame(8, $result->branchId);
        self::assertSame(7, $repository->transferData['origin'] ?? null);
        self::assertSame(8, $repository->transferData['destination'] ?? null);
        self::assertSame('Cambio de base operativa', $repository->transferData['reason'] ?? null);
    }

    public function testRejectsTransferToBranchOutsideActorScopeBeforeLoadingEquipment(): void
    {
        $repository = new LifecycleEquipmentRepositoryFake($this->equipment());
        $handler = new TransferEquipmentHandler(
            $repository,
            new LifecycleBranchScopeFake(true),
            new ImmediateAssetUnitOfWork(),
        );

        $this->expectException(DomainException::class);
        try {
            $handler->execute(
                $this->actor(['equipos.editar'], [7]),
                new TransferEquipmentCommand(30, 8, new DateTimeImmutable('2026-08-10'), 'Cambio de base operativa'),
            );
        } finally {
            self::assertNull($repository->requestedBranches);
        }
    }

    public function testRejectsTransferToInactiveBranchOfSameCompany(): void
    {
        $repository = new LifecycleEquipmentRepositoryFake($this->equipment());
        $handler = new TransferEquipmentHandler(
            $repository,
            new LifecycleBranchScopeFake(false),
            new ImmediateAssetUnitOfWork(),
        );

        $this->expectException(DomainException::class);
        $handler->execute(
            $this->actor(['equipos.editar'], [7, 8]),
            new TransferEquipmentCommand(30, 8, new DateTimeImmutable('2026-08-10'), 'Cambio de base operativa'),
        );
    }

    public function testRejectsTransferThatBreaksMovementChronology(): void
    {
        $repository = new LifecycleEquipmentRepositoryFake($this->equipment());
        $repository->latestTransferAt = new DateTimeImmutable('2026-08-10 10:00:00');
        $handler = new TransferEquipmentHandler(
            $repository,
            new LifecycleBranchScopeFake(true),
            new ImmediateAssetUnitOfWork(),
        );

        $this->expectException(DomainException::class);
        $handler->execute(
            $this->actor(['equipos.editar'], [7, 8]),
            new TransferEquipmentCommand(30, 8, new DateTimeImmutable('2026-08-10 09:00:00'), 'Cambio de base operativa'),
        );
    }

    public function testDetailsQueryScopesCurrentEquipmentAndReturnsWholeTransferHistory(): void
    {
        $readModel = new EquipmentReadModelFake([
            'equipment' => ['id' => 30, 'sucursal_id' => 7],
            'transferHistory' => [
                ['sucursal_origen_id' => 2, 'sucursal_destino_id' => 7],
            ],
        ], [['id' => 7, 'codigo' => 'BASE', 'nombre' => 'Base operativa']]);
        $query = new GetEquipmentDetails($readModel);

        $details = $query->execute($this->actor(['equipos.ver'], [7]), 30);

        self::assertSame([7], $readModel->branches);
        self::assertCount(1, $details['transferHistory']);
        self::assertSame(2, $details['transferHistory'][0]['sucursal_origen_id']);
        self::assertSame(7, $details['availableBranches'][0]['id']);
    }

    /** @param list<string> $permissions @param list<int> $branches */
    private function actor(array $permissions, array $branches): ActorContext
    {
        return new ActorContext(9, 5, false, false, ['Responsable'], $permissions, $branches);
    }

    private function equipment(): Equipment
    {
        return Equipment::reconstitute(
            30,
            5,
            7,
            new EquipmentType(1, 'Camión', true, true),
            'EQ-01',
            null,
            Equipment::ACTIVE,
            new DateTimeImmutable('2026-08-01'),
            null,
            null,
            1000,
            '50.0',
        );
    }
}

final class LifecycleEquipmentRepositoryFake implements EquipmentLifecycleRepository
{
    public bool $duplicateCode = false;
    public bool $profileUpdated = false;
    public bool $decommissioned = false;
    public ?DateTimeImmutable $latestTransferAt = null;
    /** @var array{origin:int,destination:int,reason:string}|null */
    public ?array $transferData = null;
    /** @var list<int>|null */
    public ?array $requestedBranches = null;

    public function __construct(private ?Equipment $equipment)
    {
    }

    public function findScopedForUpdate(int $companyId, int $equipmentId, ?array $branchIds): ?Equipment
    {
        $this->requestedBranches = $branchIds;

        return $this->equipment;
    }

    public function codeExistsExcluding(int $companyId, string $code, int $equipmentId): bool
    {
        return $this->duplicateCode;
    }

    public function latestTransferAtForUpdate(int $companyId, int $equipmentId): ?DateTimeImmutable
    {
        return $this->latestTransferAt;
    }

    public function updateProfile(Equipment $equipment, int $actorUserId): void
    {
        $this->profileUpdated = true;
    }

    public function decommission(Equipment $equipment, int $actorUserId): void
    {
        $this->decommissioned = true;
    }

    public function transfer(Equipment $equipment, int $originBranchId, DateTimeImmutable $occurredAt, string $reason, int $actorUserId): void
    {
        $this->transferData = [
            'origin' => $originBranchId,
            'destination' => $equipment->branchId(),
            'reason' => $reason,
        ];
    }
}

final class ImmediateAssetUnitOfWork implements AssetUnitOfWork
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}

final readonly class EquipmentWorkStatusFake implements EquipmentWorkStatus
{
    public function __construct(private bool $open)
    {
    }

    public function hasOpenOrders(int $companyId, int $equipmentId): bool
    {
        return $this->open;
    }
}

final readonly class EquipmentRelationStatusFake implements EquipmentRelationStatus
{
    public function __construct(private bool $active) {}
    public function hasActiveRelations(int $companyId, int $equipmentId): bool { return $this->active; }
}

final readonly class LifecycleBranchScopeFake implements BranchScope
{
    public function __construct(private bool $active)
    {
    }

    public function isActiveInCompany(int $companyId, int $branchId): bool
    {
        return $this->active;
    }
}

final class EquipmentReadModelFake implements EquipmentReadModel
{
    /** @var list<int>|null */
    public ?array $branches = null;

    /**
     * @param array<string, mixed>|null $details
     * @param list<array{id:int, codigo:string, nombre:string}> $availableBranches
     */
    public function __construct(private ?array $details, private array $availableBranches = [])
    {
    }

    public function findDetails(
        int $companyId,
        int $equipmentId,
        ?array $branchIds,
        int $transferPage,
        int $transfersPerPage,
        int $relationPage = 1,
        int $relationsPerPage = 20,
    ): ?array {
        $this->branches = $branchIds;

        return $this->details;
    }

    public function listAvailableBranches(int $companyId, ?array $branchIds): array
    {
        return $this->availableBranches;
    }
}
