<?php

declare(strict_types=1);

namespace Tests\Unit\Importations;

use App\Application\Identity\ActorContext;
use App\Application\Importations\ManagePreventiveLibraryTasks;
use App\Application\Importations\Port\PreventiveLibraryTaskCatalogGateway;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ManagePreventiveLibraryTasksTest extends TestCase
{
    public function testSearchMarksExistingLinksThroughGateway(): void
    {
        $gateway = $this->gateway();
        $gateway->searchResults = [[
            'id' => 10,
            'code' => 'FILTRO-ACEITE',
            'name' => 'Filtro de aceite',
            'active' => true,
            'alreadyLinked' => true,
        ]];

        $result = (new ManagePreventiveLibraryTasks($gateway))->search($this->actor(), 'filtro', 4);

        self::assertCount(1, $result);
        self::assertTrue($result[0]['alreadyLinked']);
        self::assertSame(7, $gateway->searchedCompanyId);
        self::assertSame(4, $gateway->searchedServiceTypeId);
    }

    public function testLinksExistingTaskWithoutDuplicatingCatalog(): void
    {
        $gateway = $this->gateway();
        $gateway->tasks[10] = ['id' => 10, 'code' => 'FILTRO', 'name' => 'Filtro', 'active' => true];

        $result = (new ManagePreventiveLibraryTasks($gateway))->linkExisting(
            $this->actor(),
            4,
            10,
            20,
            true,
            'Control visual',
        );

        self::assertSame(10, $result['id']);
        self::assertCount(1, $gateway->links);
        self::assertSame(4, $gateway->links[0]['serviceTypeId']);
        self::assertSame(20, $gateway->links[0]['relation']['order']);
        self::assertSame(0, $gateway->createdCount);
    }

    public function testRejectsDuplicateServiceTaskRelation(): void
    {
        $gateway = $this->gateway();
        $gateway->tasks[10] = ['id' => 10, 'code' => 'FILTRO', 'name' => 'Filtro', 'active' => true];
        $gateway->existingRelations['4:10'] = true;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ya está agregada');

        (new ManagePreventiveLibraryTasks($gateway))->linkExisting($this->actor(), 4, 10, 20, true, null);
    }

    public function testRejectsOccupiedOrder(): void
    {
        $gateway = $this->gateway();
        $gateway->tasks[10] = ['id' => 10, 'code' => 'FILTRO', 'name' => 'Filtro', 'active' => true];
        $gateway->availableOrders['4:20'] = false;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('orden solicitado ya está ocupado');

        (new ManagePreventiveLibraryTasks($gateway))->linkExisting($this->actor(), 4, 10, 20, true, null);
    }

    public function testCreatesAndLinksNewTaskInSingleGatewayOperation(): void
    {
        $gateway = $this->gateway();
        $gateway->nextTaskId = 91;

        $result = (new ManagePreventiveLibraryTasks($gateway))->createAndLink(
            $this->actor(),
            4,
            ' nueva-tarea ',
            'Nueva tarea',
            'Descripción',
            null,
            15,
            true,
            false,
            true,
            true,
            30,
            false,
            null,
        );

        self::assertSame(91, $result['id']);
        self::assertSame('NUEVA-TAREA', $result['code']);
        self::assertSame(1, $gateway->createdCount);
        self::assertSame('NUEVA-TAREA', $gateway->lastCreatedTask['code']);
        self::assertSame(30, $gateway->lastCreatedRelation['order']);
    }

    public function testRejectsDuplicateNormalizedTaskCode(): void
    {
        $gateway = $this->gateway();
        $gateway->duplicateByCode = ['id' => 3, 'code' => 'FILTRO', 'name' => 'Filtro existente'];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Ya existe la tarea FILTRO');

        (new ManagePreventiveLibraryTasks($gateway))->createAndLink(
            $this->actor(), 4, 'filtro', 'Otra', null, null, null,
            false, false, false, true, 30, true, null,
        );
    }

    public function testRejectsServiceFromAnotherCompany(): void
    {
        $gateway = $this->gateway();
        $gateway->ownedService = false;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no existe o no pertenece a la empresa activa');

        (new ManagePreventiveLibraryTasks($gateway))->search($this->actor(), 'filtro', 99);
    }

    public function testRejectsActorWithoutEditPermission(): void
    {
        $gateway = $this->gateway();
        $actor = new ActorContext(12, 7, false, false, ['Consulta'], ['importaciones.ver'], []);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No tenés permiso');

        (new ManagePreventiveLibraryTasks($gateway))->search($actor, 'filtro', 4);
    }

    private function actor(): ActorContext
    {
        return new ActorContext(12, 7, false, true, ['Administrador'], ['planes.ver', 'planes.editar'], []);
    }

    private function gateway(): object
    {
        return new class implements PreventiveLibraryTaskCatalogGateway {
            public bool $ownedService = true;
            public array $searchResults = [];
            public array $tasks = [];
            public array $existingRelations = [];
            public array $availableOrders = [];
            public ?array $duplicateByCode = null;
            public array $links = [];
            public int $createdCount = 0;
            public int $nextTaskId = 100;
            public ?array $lastCreatedTask = null;
            public ?array $lastCreatedRelation = null;
            public ?int $searchedCompanyId = null;
            public ?int $searchedServiceTypeId = null;
            public ?array $lastStatusChange = null;

            public function search(int $companyId, string $query, ?int $serviceTypeId, int $limit = 20): array
            {
                $this->searchedCompanyId = $companyId;
                $this->searchedServiceTypeId = $serviceTypeId;
                return $this->searchResults;
            }

            public function serviceBelongsToCompany(int $companyId, int $serviceTypeId): bool
            {
                return $this->ownedService;
            }

            public function findTask(int $taskId): ?array
            {
                return $this->tasks[$taskId] ?? null;
            }

            public function relationExists(int $serviceTypeId, int $taskId): bool
            {
                return $this->existingRelations[$serviceTypeId . ':' . $taskId] ?? false;
            }

            public function orderIsAvailable(int $serviceTypeId, int $order): bool
            {
                return $this->availableOrders[$serviceTypeId . ':' . $order] ?? true;
            }

            public function link(int $serviceTypeId, int $taskId, array $relation): void
            {
                $this->links[] = compact('serviceTypeId', 'taskId', 'relation');
            }

            public function findByNormalizedCode(string $normalizedCode): ?array
            {
                return $this->duplicateByCode;
            }

            public function createAndLink(int $serviceTypeId, array $task, array $relation): int
            {
                ++$this->createdCount;
                $this->lastCreatedTask = $task;
                $this->lastCreatedRelation = $relation;
                return $this->nextTaskId;
            }

            public function setActive(int $companyId, int $serviceTypeId, int $taskId, bool $active): void
            {
                $this->lastStatusChange = compact('companyId', 'serviceTypeId', 'taskId', 'active');
            }
        };
    }
}
