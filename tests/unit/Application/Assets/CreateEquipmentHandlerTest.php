<?php

declare(strict_types=1);

use App\Application\Assets\CreateEquipmentCommand;
use App\Application\Assets\CreateEquipmentHandler;
use App\Application\Assets\Port\BranchScope;
use App\Application\Assets\Port\EquipmentRepository;
use App\Application\Assets\Port\EquipmentTypeCatalog;
use App\Application\Identity\ActorContext;
use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentType;
use PHPUnit\Framework\TestCase;

final class CreateEquipmentHandlerTest extends TestCase
{
    public function testCreatesEquipmentInsideActorsCompanyAndBranch(): void
    {
        $repository = new CreateEquipmentRepositoryFake();
        $handler = new CreateEquipmentHandler(
            $repository,
            new StaticEquipmentTypeCatalog(new EquipmentType(3, 'Camión', true, false)),
            new StaticBranchScope(true),
        );

        $result = $handler->execute(
            $this->actor(['equipos.editar'], [7]),
            new CreateEquipmentCommand(7, 3, ' cam-01 ', null, new DateTimeImmutable('2026-08-08')),
        );

        self::assertSame(41, $result->equipmentId);
        self::assertSame(5, $result->companyId);
        self::assertSame('CAM-01', $result->code);
        self::assertSame(5, $repository->added?->companyId());
        self::assertSame(7, $repository->added?->branchId());
    }

    public function testRejectsBranchOutsideActorsScope(): void
    {
        $handler = new CreateEquipmentHandler(
            new CreateEquipmentRepositoryFake(),
            new StaticEquipmentTypeCatalog(new EquipmentType(3, 'Camión', true, false)),
            new StaticBranchScope(true),
        );

        $this->expectException(DomainException::class);
        $handler->execute(
            $this->actor(['equipos.editar'], [7]),
            new CreateEquipmentCommand(8, 3, 'CAM-01', null, new DateTimeImmutable('2026-08-08')),
        );
    }

    public function testRejectsDuplicateCodeWithinCompany(): void
    {
        $repository = new CreateEquipmentRepositoryFake();
        $repository->duplicate = true;
        $handler = new CreateEquipmentHandler(
            $repository,
            new StaticEquipmentTypeCatalog(new EquipmentType(3, 'Camión', true, false)),
            new StaticBranchScope(true),
        );

        $this->expectException(DomainException::class);
        $handler->execute(
            $this->actor(['equipos.editar'], [7]),
            new CreateEquipmentCommand(7, 3, 'CAM-01', null, new DateTimeImmutable('2026-08-08')),
        );
    }

    /** @param list<string> $permissions @param list<int> $branches */
    private function actor(array $permissions, array $branches): ActorContext
    {
        return new ActorContext(9, 5, false, false, ['Responsable de mantenimiento'], $permissions, $branches);
    }
}

final class CreateEquipmentRepositoryFake implements EquipmentRepository
{
    public bool $duplicate = false;
    public ?Equipment $added = null;

    public function codeExists(int $companyId, string $code): bool { return $this->duplicate; }
    public function add(Equipment $equipment, int $actorUserId): int { $this->added = $equipment; return 41; }
    public function findForUpdate(int $equipmentId, int $companyId): ?Equipment { return null; }
    public function updateCurrentUsage(Equipment $equipment, int $actorUserId): void {}
}

final readonly class StaticEquipmentTypeCatalog implements EquipmentTypeCatalog
{
    public function __construct(private ?EquipmentType $type) {}
    public function findActiveById(int $typeId): ?EquipmentType { return $this->type?->id() === $typeId ? $this->type : null; }
}

final readonly class StaticBranchScope implements BranchScope
{
    public function __construct(private bool $valid) {}
    public function isActiveInCompany(int $companyId, int $branchId): bool { return $this->valid; }
}
