<?php

declare(strict_types=1);

use App\Application\Assets\CreateEquipmentCommand;
use App\Application\Assets\CreateEquipmentHandler;
use App\Application\Assets\Port\BranchScope;
use App\Application\Assets\Port\BrandRepository;
use App\Application\Assets\Port\EquipmentModelRepository;
use App\Application\Assets\Port\EquipmentRepository;
use App\Application\Assets\Port\EquipmentTypeCatalog;
use App\Application\Identity\ActorContext;
use App\Domain\Assets\Brand;
use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentModel;
use App\Domain\Assets\EquipmentType;
use PHPUnit\Framework\TestCase;

final class EquipmentTechnicalHandlersTest extends TestCase
{
    public function testCreatesEquipmentWithCompatibleTenantCatalogValues(): void
    {
        $equipment = new Phase2CTechnicalEquipmentRepositoryFake();
        $handler = new CreateEquipmentHandler(
            $equipment,
            new Phase2CTechnicalTypeCatalogFake(),
            new Phase2CTechnicalBranchFake(),
            new Phase2CTechnicalBrandRepositoryFake(),
            new Phase2CTechnicalModelRepositoryFake(EquipmentModel::reconstitute(20, 5, 10, 3, 'R 450', true)),
        );
        $result = $handler->execute(
            $this->actor(),
            new CreateEquipmentCommand(7, 3, 'TR-01', null, new DateTimeImmutable('2026-08-08'), null, 10, 20, 2024, 'ABC', 'M-1'),
        );

        self::assertSame(401, $result->equipmentId);
        self::assertSame(10, $equipment->added?->brandId());
        self::assertSame(20, $equipment->added?->modelId());
    }

    public function testRejectsModelThatDoesNotBelongToSelectedType(): void
    {
        $equipment = new Phase2CTechnicalEquipmentRepositoryFake();
        $handler = new CreateEquipmentHandler(
            $equipment,
            new Phase2CTechnicalTypeCatalogFake(),
            new Phase2CTechnicalBranchFake(),
            new Phase2CTechnicalBrandRepositoryFake(),
            new Phase2CTechnicalModelRepositoryFake(EquipmentModel::reconstitute(20, 5, 10, 99, 'Incompatible', true)),
        );

        try {
            $handler->execute(
                $this->actor(),
                new CreateEquipmentCommand(7, 3, 'TR-01', null, new DateTimeImmutable('2026-08-08'), null, 10, 20),
            );
            self::fail('El modelo incompatible debió rechazarse.');
        } catch (DomainException) {
            self::assertNull($equipment->added);
        }
    }

    private function actor(): ActorContext
    {
        return new ActorContext(4, 5, false, false, ['Responsable'], ['equipos.editar'], [7]);
    }
}

final class Phase2CTechnicalEquipmentRepositoryFake implements EquipmentRepository
{
    public ?Equipment $added = null;
    public function codeExists(int $companyId, string $code): bool { return false; }
    public function add(Equipment $equipment, int $actorUserId): int { $this->added = $equipment; return 401; }
    public function findForUpdate(int $equipmentId, int $companyId): ?Equipment { return null; }
    public function updateCurrentUsage(Equipment $equipment, int $actorUserId): void {}
}

final class Phase2CTechnicalTypeCatalogFake implements EquipmentTypeCatalog
{
    public function findActiveById(int $typeId): ?EquipmentType
    {
        return $typeId === 3 ? new EquipmentType(3, 'Tractor', true, true) : null;
    }
}

final class Phase2CTechnicalBranchFake implements BranchScope
{
    public function isActiveInCompany(int $companyId, int $branchId): bool { return $companyId === 5 && $branchId === 7; }
}

final class Phase2CTechnicalBrandRepositoryFake implements BrandRepository
{
    public function nameExists(int $companyId, string $name, ?int $excludingId = null): bool { return false; }
    public function add(Brand $brand, int $actorUserId): int { return 0; }
    public function findForUpdate(int $companyId, int $brandId): ?Brand { return null; }
    public function save(Brand $brand, int $actorUserId): void {}
    public function findActiveById(int $companyId, int $brandId): ?Brand { return $companyId === 5 && $brandId === 10 ? Brand::reconstitute(10, 5, 'Scania', true) : null; }
    public function hasActiveModels(int $companyId, int $brandId): bool { return false; }
}

final readonly class Phase2CTechnicalModelRepositoryFake implements EquipmentModelRepository
{
    public function __construct(private EquipmentModel $model) {}
    public function nameExists(int $companyId, int $brandId, int $typeId, string $name, ?int $excludingId = null): bool { return false; }
    public function add(EquipmentModel $model, int $actorUserId): int { return 0; }
    public function findForUpdate(int $companyId, int $modelId): ?EquipmentModel { return null; }
    public function save(EquipmentModel $model, int $actorUserId): void {}
    public function findActiveById(int $companyId, int $modelId): ?EquipmentModel { return $companyId === 5 && $modelId === 20 ? $this->model : null; }
}
