<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Organization\AssignUserCompanyHandler;
use App\Application\Organization\AssignUserRolesHandler;
use App\Application\Organization\CreateCompanyHandler;
use App\Application\Organization\GetOrganizationOverview;
use App\Application\Organization\Port\OrganizationAdministrationPort;
use CodeIgniter\Test\CIUnitTestCase;

final class OrganizationHandlersTest extends CIUnitTestCase
{
    public function testOnlySuperAdminCanReadGlobalOverview(): void
    {
        $port = new RecordingOrganizationAdministration();
        $useCase = new GetOrganizationOverview($port);

        $this->expectException(\DomainException::class);

        $useCase->execute($this->companyAdministrator());
    }

    public function testSuperAdminCanCreateCompany(): void
    {
        $port = new RecordingOrganizationAdministration();
        $useCase = new CreateCompanyHandler($port);

        $companyId = $useCase->execute($this->superAdmin(), [
            'razon_social'    => 'Empresa Dos SA',
            'nombre_fantasia' => 'Empresa Dos',
            'cuit'            => null,
            'email'           => null,
            'telefono'        => null,
        ]);

        $this->assertSame(77, $companyId);
        $this->assertSame(99, $port->lastActorUserId);
    }

    public function testCompanyAssignmentRequiresReason(): void
    {
        $port = new RecordingOrganizationAdministration();
        $useCase = new AssignUserCompanyHandler($port);

        $this->expectException(\DomainException::class);

        $useCase->execute($this->superAdmin(), 3, 2, 'no');
    }

    public function testCompanyAssignmentReachesPortWithGlobalActor(): void
    {
        $port = new RecordingOrganizationAdministration();
        $useCase = new AssignUserCompanyHandler($port);

        $useCase->execute($this->superAdmin(), 3, 2, 'Cambio aprobado');

        $this->assertSame([3, 2, 'Cambio aprobado', 99], $port->companyAssignment);
    }

    public function testRoleAssignmentNormalizesDuplicates(): void
    {
        $port = new RecordingOrganizationAdministration();
        $useCase = new AssignUserRolesHandler($port);

        $useCase->execute($this->superAdmin(), 3, [2, 2, 1], 'Roles aprobados');

        $this->assertSame([3, [2, 1], 'Roles aprobados', 99], $port->roleAssignment);
    }

    public function testRoleAssignmentRequiresAtLeastOneRole(): void
    {
        $port = new RecordingOrganizationAdministration();
        $useCase = new AssignUserRolesHandler($port);

        $this->expectException(\DomainException::class);

        $useCase->execute($this->superAdmin(), 3, [], 'Roles aprobados');
    }

    private function superAdmin(): ActorContext
    {
        return new ActorContext(99, null, true, false, [], [], []);
    }

    private function companyAdministrator(): ActorContext
    {
        return new ActorContext(1, 1, false, true, ['Administrador'], ['usuarios.editar'], []);
    }
}

final class RecordingOrganizationAdministration implements OrganizationAdministrationPort
{
    public ?int $lastActorUserId = null;

    /** @var array{int, int, string, int}|null */
    public ?array $companyAssignment = null;

    /** @var array{int, list<int>, string, int}|null */
    public ?array $roleAssignment = null;

    public function overview(): array
    {
        return ['companies' => [], 'users' => [], 'roles' => []];
    }

    public function createCompany(array $data, int $actorUserId): int
    {
        $this->lastActorUserId = $actorUserId;

        return 77;
    }

    public function updateCompany(int $companyId, array $data, int $actorUserId): void
    {
        $this->lastActorUserId = $actorUserId;
    }

    public function assignUserToCompany(int $userId, int $companyId, string $reason, int $actorUserId): void
    {
        $this->companyAssignment = [$userId, $companyId, $reason, $actorUserId];
    }

    public function assignRolesToUser(int $userId, array $roleIds, string $reason, int $actorUserId): void
    {
        $this->roleAssignment = [$userId, $roleIds, $reason, $actorUserId];
    }
}
