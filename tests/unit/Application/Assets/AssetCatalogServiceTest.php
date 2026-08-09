<?php

declare(strict_types=1);

use App\Application\Assets\AssetCatalogService;
use App\Application\Assets\CreateBrandCommand;
use App\Application\Assets\CreateEquipmentModelCommand;
use App\Application\Assets\InactivateBrandCommand;
use App\Application\Assets\Port\AssetCatalogReadModel;
use App\Application\Assets\Port\AssetUnitOfWork;
use App\Application\Assets\Port\BrandRepository;
use App\Application\Assets\Port\EquipmentModelRepository;
use App\Application\Assets\Port\EquipmentTypeCatalog;
use App\Application\Identity\ActorContext;
use App\Domain\Assets\Brand;
use App\Domain\Assets\EquipmentModel;
use App\Domain\Assets\EquipmentType;
use PHPUnit\Framework\TestCase;

final class AssetCatalogServiceTest extends TestCase
{
    public function testCreatesTenantScopedBrandAndCompatibleModel(): void
    {
        $brands = new Phase2CBrandRepositoryFake();
        $models = new Phase2CModelRepositoryFake();
        $service = $this->service($brands, $models);

        self::assertSame(101, $service->createBrand($this->actor(), new CreateBrandCommand('Scania')));
        self::assertSame(5, $brands->added?->companyId());
        $brands->stored = Brand::reconstitute(7, 5, 'Scania', true);
        self::assertSame(201, $service->createModel($this->actor(), new CreateEquipmentModelCommand(7, 9, 'R 450')));
        self::assertSame(7, $models->added?->brandId());
        self::assertSame(9, $models->added?->equipmentTypeId());
    }

    public function testRejectsModelFromInactiveOrForeignBrand(): void
    {
        $service = $this->service(new Phase2CBrandRepositoryFake(), new Phase2CModelRepositoryFake());

        $this->expectException(DomainException::class);
        $service->createModel($this->actor(), new CreateEquipmentModelCommand(99, 9, 'Ajeno'));
    }

    public function testBrandWithActiveModelsCannotBeInactivated(): void
    {
        $brands = new Phase2CBrandRepositoryFake();
        $brands->stored = Brand::reconstitute(7, 5, 'Scania', true);
        $brands->activeModels = true;
        $service = $this->service($brands, new Phase2CModelRepositoryFake());

        $this->expectException(DomainException::class);
        $service->inactivateBrand($this->actor(), new InactivateBrandCommand(7));
    }

    public function testViewerCannotRequestInactiveCatalogs(): void
    {
        $service = $this->service(new Phase2CBrandRepositoryFake(), new Phase2CModelRepositoryFake());
        $viewer = new ActorContext(3, 5, false, true, ['Consulta'], ['equipos.ver'], []);

        $this->expectException(DomainException::class);
        $service->list($viewer, true);
    }

    private function actor(): ActorContext
    {
        return new ActorContext(3, 5, false, true, ['Responsable'], ['equipos.ver', 'equipos.editar'], []);
    }

    private function service(Phase2CBrandRepositoryFake $brands, Phase2CModelRepositoryFake $models): AssetCatalogService
    {
        return new AssetCatalogService(
            $brands,
            $models,
            new class implements EquipmentTypeCatalog {
                public function findActiveById(int $typeId): ?EquipmentType { return $typeId === 9 ? new EquipmentType(9, 'Tractor', true, true) : null; }
            },
            new class implements AssetCatalogReadModel {
                public function list(int $companyId, bool $includeInactive): array { return ['brands' => [], 'models' => [], 'types' => []]; }
            },
            new class implements AssetUnitOfWork { public function transactional(callable $operation): mixed { return $operation(); } },
        );
    }
}

final class Phase2CBrandRepositoryFake implements BrandRepository
{
    public ?Brand $added = null;
    public ?Brand $stored = null;
    public ?Brand $active = null;
    public bool $activeModels = false;
    public function nameExists(int $companyId, string $name, ?int $excludingId = null): bool { return false; }
    public function add(Brand $brand, int $actorUserId): int { $this->added = $brand; return 101; }
    public function findForUpdate(int $companyId, int $brandId): ?Brand { return $this->stored; }
    public function save(Brand $brand, int $actorUserId): void { $this->stored = $brand; }
    public function findActiveById(int $companyId, int $brandId): ?Brand { return $this->active; }
    public function hasActiveModels(int $companyId, int $brandId): bool { return $this->activeModels; }
}

final class Phase2CModelRepositoryFake implements EquipmentModelRepository
{
    public ?EquipmentModel $added = null;
    public function nameExists(int $companyId, int $brandId, int $typeId, string $name, ?int $excludingId = null): bool { return false; }
    public function add(EquipmentModel $model, int $actorUserId): int { $this->added = $model; return 201; }
    public function findForUpdate(int $companyId, int $modelId): ?EquipmentModel { return null; }
    public function save(EquipmentModel $model, int $actorUserId): void {}
    public function findActiveById(int $companyId, int $modelId): ?EquipmentModel { return null; }
}
