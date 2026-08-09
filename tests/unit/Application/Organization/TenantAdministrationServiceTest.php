<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Organization\Port\TenantAdministrationPort;
use App\Application\Organization\TenantAdministrationService;
use CodeIgniter\Test\CIUnitTestCase;

final class TenantAdministrationServiceTest extends CIUnitTestCase
{
    public function testScopesBranchOverviewToActorCompany(): void
    {
        $port = new RecordingTenantAdministration();
        $service = new TenantAdministrationService($port);

        $service->branchesOverview($this->administrator());

        self::assertSame(7, $port->lastCompanyId);
    }

    public function testSuperAdminCannotUseTenantAdministration(): void
    {
        $service = new TenantAdministrationService(new RecordingTenantAdministration());

        $this->expectException(\DomainException::class);
        $service->branchesOverview(new ActorContext(99, null, true, false, [], [], []));
    }

    public function testPermissionIsEnforcedInsideUseCase(): void
    {
        $service = new TenantAdministrationService(new RecordingTenantAdministration());
        $actor = new ActorContext(3, 7, false, false, ['Consulta'], [], [2]);

        $this->expectException(\DomainException::class);
        $service->usersOverview($actor);
    }

    public function testNormalizesBranchBeforeCallingPort(): void
    {
        $port = new RecordingTenantAdministration();
        $service = new TenantAdministrationService($port);

        $service->createBranch($this->administrator(), [
            'codigo' => ' norte ',
            'nombre' => ' Base Norte ',
            'direccion' => '',
            'email_alertas' => ' alertas@example.test ',
        ]);

        self::assertSame('NORTE', $port->branchData['codigo']);
        self::assertSame('Base Norte', $port->branchData['nombre']);
        self::assertNull($port->branchData['direccion']);
        self::assertSame(7, $port->lastCompanyId);
    }

    public function testCannotDeactivateOwnAccount(): void
    {
        $service = new TenantAdministrationService(new RecordingTenantAdministration());

        $this->expectException(\DomainException::class);
        $service->updateUser($this->administrator(), 1, [
            'nombre' => 'Administrador',
            'email' => 'admin@example.test',
            'activo' => 0,
        ], 'Cambio aprobado');
    }

    public function testCannotChangeOwnAccess(): void
    {
        $service = new TenantAdministrationService(new RecordingTenantAdministration());

        $this->expectException(\DomainException::class);
        $service->assignUserAccess($this->administrator(), 1, [1], [], 'Cambio aprobado');
    }

    public function testCreateUserNormalizesIdsAndKeepsCompanyScope(): void
    {
        $port = new RecordingTenantAdministration();
        $service = new TenantAdministrationService($port);

        $service->createUser(
            $this->administrator(),
            ['nombre' => ' Usuario ', 'email' => ' USER@example.test ', 'password' => 'Segura123'],
            [5, 5, 0],
            [3, 3, -1],
            ' Alta aprobada ',
        );

        self::assertSame(7, $port->lastCompanyId);
        self::assertSame([5], $port->roleIds);
        self::assertSame([3], $port->branchIds);
        self::assertSame('user@example.test', $port->userData['email']);
        self::assertSame('Alta aprobada', $port->reason);
    }

    private function administrator(): ActorContext
    {
        return new ActorContext(
            1,
            7,
            false,
            true,
            ['Administrador'],
            ['sucursales.ver', 'sucursales.editar', 'usuarios.ver', 'usuarios.editar', 'roles.editar'],
            [],
        );
    }
}

final class RecordingTenantAdministration implements TenantAdministrationPort
{
    public ?int $lastCompanyId = null;
    public array $branchData = [];
    public array $userData = [];
    public array $roleIds = [];
    public array $branchIds = [];
    public ?string $reason = null;

    public function branchesOverview(int $companyId): array
    {
        $this->lastCompanyId = $companyId;

        return ['company' => [], 'branches' => []];
    }

    public function usersOverview(int $companyId): array
    {
        $this->lastCompanyId = $companyId;

        return ['company' => [], 'users' => [], 'roles' => [], 'branches' => []];
    }

    public function createBranch(int $companyId, array $data, int $actorUserId): int
    {
        $this->lastCompanyId = $companyId;
        $this->branchData = $data;

        return 10;
    }

    public function updateBranch(int $companyId, int $branchId, array $data, int $actorUserId): void
    {
        $this->lastCompanyId = $companyId;
        $this->branchData = $data;
    }

    public function createUser(int $companyId, array $data, array $roleIds, array $branchIds, string $reason, int $actorUserId): int
    {
        $this->lastCompanyId = $companyId;
        $this->userData = $data;
        $this->roleIds = $roleIds;
        $this->branchIds = $branchIds;
        $this->reason = $reason;

        return 20;
    }

    public function updateUser(int $companyId, int $userId, array $data, string $reason, int $actorUserId): void
    {
        $this->lastCompanyId = $companyId;
    }

    public function assignUserAccess(int $companyId, int $userId, array $roleIds, array $branchIds, string $reason, int $actorUserId): void
    {
        $this->lastCompanyId = $companyId;
    }

    public function resetUserPassword(int $companyId, int $userId, string $password, string $reason, int $actorUserId): void
    {
        $this->lastCompanyId = $companyId;
    }
}
