<?php

declare(strict_types=1);

namespace Tests\Unit\Importations;

use App\Application\Identity\ActorContext;
use App\Application\Importations\ManagePreventiveLibraryTasks;
use App\Application\Importations\Port\PreventiveLibraryTaskCatalogGateway;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ManagePreventiveLibraryTaskStatusTest extends TestCase
{
    public function testActivaYDesactivaUnaTareaDentroDelScopeDeLaEmpresa(): void
    {
        $gateway = new FakePreventiveLibraryTaskCatalogGateway();
        $manager = new ManagePreventiveLibraryTasks($gateway);
        $actor = $this->actor(['importaciones.cargar']);

        $manager->setActive($actor, 10, 20, false);
        self::assertSame([1, 10, 20, false], $gateway->lastStatusChange);

        $manager->setActive($actor, 10, 20, true);
        self::assertSame([1, 10, 20, true], $gateway->lastStatusChange);
    }

    public function testRechazaCambioSinPermiso(): void
    {
        $gateway = new FakePreventiveLibraryTaskCatalogGateway();
        $manager = new ManagePreventiveLibraryTasks($gateway);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No tenes permiso');
        $manager->setActive($this->actor([]), 10, 20, false);
    }

    public function testRechazaUnaTareaQueNoPerteneceAlServicio(): void
    {
        $gateway = new FakePreventiveLibraryTaskCatalogGateway();
        $gateway->relationExists = false;
        $manager = new ManagePreventiveLibraryTasks($gateway);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no pertenece al servicio');
        $manager->setActive($this->actor(['importaciones.cargar']), 10, 20, false);
    }

    private function actor(array $permissions): ActorContext
    {
        return new ActorContext(
            userId: 7,
            companyId: 1,
            superAdmin: false,
            allCompanyBranches: true,
            roles: ['Administrador'],
            permissions: $permissions,
            branchIds: [],
        );
    }
}

final class FakePreventiveLibraryTaskCatalogGateway implements PreventiveLibraryTaskCatalogGateway
{
    public bool $relationExists = true;
    public ?array $lastStatusChange = null;

    public function search(int $companyId, string $query, ?int $serviceTypeId, int $limit = 20): array { return []; }
    public function serviceBelongsToCompany(int $companyId, int $serviceTypeId): bool { return $companyId === 1 && $serviceTypeId === 10; }
    public function findTask(int $taskId): ?array { return ['id' => $taskId, 'code' => 'T-1', 'name' => 'Tarea', 'active' => true]; }
    public function relationExists(int $serviceTypeId, int $taskId): bool { return $this->relationExists; }
    public function orderIsAvailable(int $serviceTypeId, int $order): bool { return true; }
    public function link(int $serviceTypeId, int $taskId, array $relation): void {}
    public function findByNormalizedCode(string $normalizedCode): ?array { return null; }
    public function createAndLink(int $serviceTypeId, array $task, array $relation): int { return 99; }

    public function setActive(int $companyId, int $serviceTypeId, int $taskId, bool $active): void
    {
        $this->lastStatusChange = [$companyId, $serviceTypeId, $taskId, $active];
    }
}
