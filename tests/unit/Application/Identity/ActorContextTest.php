<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use CodeIgniter\Test\CIUnitTestCase;

final class ActorContextTest extends CIUnitTestCase
{
    public function testCommonUserRequiresACompany(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ActorContext(10, null, false, false, [], [], []);
    }

    public function testSuperAdminCannotBelongToACompany(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ActorContext(10, 1, true, false, [], [], []);
    }

    public function testAdministratorAccessesEveryBranchOfItsCompanyOnly(): void
    {
        $actor = new ActorContext(
            10,
            4,
            false,
            true,
            ['Administrador'],
            ['equipos.ver'],
            [],
        );

        $this->assertTrue($actor->canAccessBranch(4, 999));
        $this->assertFalse($actor->canAccessBranch(5, 999));
        $this->assertTrue($actor->hasAllCompanyBranches());
        $this->assertTrue($actor->hasPermission('equipos.ver'));
        $this->assertFalse($actor->hasPermission('equipos.editar'));
    }

    public function testRestrictedUserOnlyAccessesAssignedBranches(): void
    {
        $actor = new ActorContext(11, 4, false, false, ['Consulta'], ['equipos.ver'], [7, 8]);

        $this->assertFalse($actor->hasAllCompanyBranches());
        $this->assertTrue($actor->canAccessBranch(4, 7));
        $this->assertFalse($actor->canAccessBranch(4, 9));
        $this->assertFalse($actor->canAccessBranch(5, 7));
    }

    public function testSuperAdminDoesNotInheritTenantPermissions(): void
    {
        $actor = new ActorContext(1, null, true, false, [], [], []);

        $this->assertTrue($actor->isSuperAdmin());
        $this->assertTrue($actor->canAccessCompany(999));
        $this->assertFalse($actor->hasPermission('ordenes.cerrar'));
    }

    public function testSessionPayloadRoundTripPreservesScope(): void
    {
        $original = new ActorContext(11, 4, false, false, ['Tecnico'], ['lecturas.cargar'], [7]);

        $restored = ActorContext::fromArray($original->toArray());

        $this->assertSame($original->toArray(), $restored->toArray());
    }
}
