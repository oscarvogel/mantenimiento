<?php

declare(strict_types=1);

use App\Application\Assets\DecommissionEquipmentCommand;
use App\Application\Assets\DecommissionEquipmentHandler;
use App\Application\Assets\GetEquipmentDetails;
use App\Application\Assets\Port\AssetUnitOfWork;
use App\Application\Assets\Port\AssetClock;
use App\Application\Assets\Port\BranchScope;
use App\Application\Assets\Port\EquipmentLifecycleRepository;
use App\Application\Assets\Port\EquipmentReadModel;
use App\Application\Assets\Port\EquipmentWorkStatus;
use App\Application\Assets\Port\EquipmentTypeCatalog;
use App\Application\Assets\Port\EquipmentTypeChangeGuard;
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

    public function testUpdatesActiveTypeAndRegistrationDateTransactionally(): void
    {
        $repository = new LifecycleEquipmentRepositoryFake(Equipment::create(
            5, 7, new EquipmentType(1, 'Camión', true, true), 'EQ-01', null, new DateTimeImmutable('2026-08-01'),
        ));
        $handler = new UpdateEquipmentHandler(
            $repository, new ImmediateAssetUnitOfWork(), null, null,
            new LifecycleEquipmentTypeCatalogFake(new EquipmentType(2, 'Máquina', true, true)),
            new FixedAssetClock(new DateTimeImmutable('2026-08-10')),
            new EquipmentTypeChangeGuardFake(),
        );

        $handler->execute(
            $this->actor(['equipos.editar'], [7]),
            new UpdateEquipmentCommand(30, 'EQ-01', null, null, typeId: 2, registeredAt: new DateTimeImmutable('2026-08-09')),
        );

        self::assertTrue($repository->profileUpdated);
        self::assertSame(2, $repository->updatedEquipment?->type()->id());
        self::assertSame('2026-08-09', $repository->updatedEquipment?->registeredAt()->format('Y-m-d'));
    }

    public function testRejectsTypeThatWouldHideHistoricalKilometers(): void
    {
        $repository = new LifecycleEquipmentRepositoryFake(Equipment::create(
            5, 7, new EquipmentType(1, 'Camión', true, true), 'EQ-01', null, new DateTimeImmutable('2026-08-01'),
        ));
        $repository->recordedUsage['kilometraje'] = true;
        $handler = new UpdateEquipmentHandler(
            $repository, new ImmediateAssetUnitOfWork(), null, null,
            new LifecycleEquipmentTypeCatalogFake(new EquipmentType(2, 'Otro', false, true)),
            null,
            new EquipmentTypeChangeGuardFake(),
        );

        $this->expectException(DomainException::class);
        try {
            $handler->execute(
                $this->actor(['equipos.editar'], [7]),
                new UpdateEquipmentCommand(30, 'EQ-01', null, null, typeId: 2),
            );
        } finally {
            self::assertFalse($repository->profileUpdated);
        }
    }

    public function testRejectsTypeChangeWhileWorkOrderIsOpen(): void
    {
        $repository = new LifecycleEquipmentRepositoryFake(Equipment::create(
            5, 7, new EquipmentType(1, 'Camión', true, true), 'EQ-01', null, new DateTimeImmutable('2026-08-01'),
        ));
        $handler = new UpdateEquipmentHandler(
            $repository, new ImmediateAssetUnitOfWork(), null, null,
            new LifecycleEquipmentTypeCatalogFake(new EquipmentType(2, 'Máquina', true, true)), null,
            new EquipmentTypeChangeGuardFake(openWorkOrders: true),
        );

        $this->expectException(DomainException::class);
        try {
            $handler->execute(
                $this->actor(['equipos.editar'], [7]),
                new UpdateEquipmentCommand(30, 'EQ-01', null, null, typeId: 2),
            );
        } finally {
            self::assertFalse($repository->profileUpdated);
        }
    }

    public function testRejectsTypeThatCannotSupportAnActiveHoursPlan(): void
    {
        $repository = new LifecycleEquipmentRepositoryFake(Equipment::create(
            5, 7, new EquipmentType(1, 'Camión', true, true), 'EQ-01', null, new DateTimeImmutable('2026-08-01'),
        ));
        $handler = new UpdateEquipmentHandler(
            $repository, new ImmediateAssetUnitOfWork(), null, null,
            new LifecycleEquipmentTypeCatalogFake(new EquipmentType(2, 'Acoplado', true, false)), null,
            new EquipmentTypeChangeGuardFake(activeHoursPlan: true),
        );

        $this->expectException(DomainException::class);
        try {
            $handler->execute(
                $this->actor(['equipos.editar'], [7]),
                new UpdateEquipmentCommand(30, 'EQ-01', null, null, typeId: 2),
            );
        } finally {
            self::assertFalse($repository->profileUpdated);
        }
    }

    public function testRejectsTypeThatCannotSupportAnActiveKilometersPlan(): void
    {
        $repository = new LifecycleEquipmentRepositoryFake(Equipment::create(
            5, 7, new EquipmentType(1, 'Camión', true, true), 'EQ-01', null, new DateTimeImmutable('2026-08-01'),
        ));
        $handler = new UpdateEquipmentHandler(
            $repository, new ImmediateAssetUnitOfWork(), null, null,
            new LifecycleEquipmentTypeCatalogFake(new EquipmentType(2, 'Máquina', false, true)), null,
            new EquipmentTypeChangeGuardFake(activeKilometersPlan: true),
        );

        $this->expectException(DomainException::class);
        try {
            $handler->execute(
                $this->actor(['equipos.editar'], [7]),
                new UpdateEquipmentCommand(30, 'EQ-01', null, null, typeId: 2),
            );
        } finally {
            self::assertFalse($repository->profileUpdated);
        }
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
    /** @var array<string, bool> */
    public array $recordedUsage = ['kilometraje' => false, 'horometro' => false];
    public ?Equipment $updatedEquipment = null;

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

    public function hasRecordedUsage(int $companyId, int $equipmentId, string $metric): bool
    {
        return $this->recordedUsage[$metric] ?? false;
    }

    public function updateProfile(Equipment $equipment, int $actorUserId): void
    {
        $this->profileUpdated = true;
        $this->updatedEquipment = $equipment;
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

final readonly class LifecycleEquipmentTypeCatalogFake implements EquipmentTypeCatalog
{
    public function __construct(private ?EquipmentType $type) {}
    public function findActiveById(int $typeId): ?EquipmentType
    {
        return $this->type?->id() === $typeId ? $this->type : null;
    }
}

final readonly class FixedAssetClock implements AssetClock
{
    public function __construct(private DateTimeImmutable $date) {}
    public function today(): DateTimeImmutable { return $this->date; }
}

final readonly class EquipmentTypeChangeGuardFake implements EquipmentTypeChangeGuard
{
    public function __construct(
        private bool $openWorkOrders = false,
        private bool $activeKilometersPlan = false,
        private bool $activeHoursPlan = false,
    ) {}

    public function hasOpenWorkOrders(int $companyId, int $equipmentId): bool { return $this->openWorkOrders; }
    public function hasActivePlanUsingKilometers(int $companyId, int $equipmentId): bool { return $this->activeKilometersPlan; }
    public function hasActivePlanUsingHours(int $companyId, int $equipmentId): bool { return $this->activeHoursPlan; }
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
