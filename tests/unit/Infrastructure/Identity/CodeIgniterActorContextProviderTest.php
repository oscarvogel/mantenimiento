<?php

declare(strict_types=1);

use App\Infrastructure\Identity\CodeIgniterActorContextProvider;
use App\Models\UsuarioModel;
use CodeIgniter\Test\CIUnitTestCase;

final class CodeIgniterActorContextProviderTest extends CIUnitTestCase
{
    public function testLoadsGlobalSuperAdminWithoutTenantRoles(): void
    {
        $users = $this->createMock(UsuarioModel::class);
        $users->expects($this->once())->method('findActiveById')->with(2)->willReturn([
            'id'            => 2,
            'empresa_id'    => null,
            'es_superadmin' => 1,
        ]);

        $actor = (new CodeIgniterActorContextProvider($users))->load(2);

        $this->assertNotNull($actor);
        $this->assertTrue($actor->isSuperAdmin());
        $this->assertNull($actor->companyId());
    }

    public function testLoadsCompanyAdministratorWithAllCompanyBranches(): void
    {
        $users = $this->createMock(UsuarioModel::class);
        $users->method('findActiveById')->willReturn([
            'id'            => 3,
            'empresa_id'    => 8,
            'es_superadmin' => 0,
        ]);
        $users->expects($this->once())->method('roles')->with(3)->willReturn([
            ['nombre' => 'Administrador'],
        ]);
        $users->expects($this->once())->method('permisos')->with(3)->willReturn(['equipos.ver']);
        $users->expects($this->once())->method('companyIsActive')->with(8)->willReturn(true);
        $users->expects($this->once())->method('sucursales')->with(3, true)->willReturn([
            ['id' => 10],
            ['id' => 11],
        ]);

        $actor = (new CodeIgniterActorContextProvider($users))->load(3);

        $this->assertNotNull($actor);
        $this->assertFalse($actor->isSuperAdmin());
        $this->assertTrue($actor->canAccessBranch(8, 999));
        $this->assertFalse($actor->canAccessBranch(9, 10));
    }

    public function testRejectsInvalidGlobalAndCompanyCombination(): void
    {
        $users = $this->createMock(UsuarioModel::class);
        $users->method('findActiveById')->willReturn([
            'id'            => 3,
            'empresa_id'    => 8,
            'es_superadmin' => 1,
        ]);

        $this->assertNull((new CodeIgniterActorContextProvider($users))->load(3));
    }

    public function testRejectsUserWhoseCompanyIsInactive(): void
    {
        $users = $this->createMock(UsuarioModel::class);
        $users->method('findActiveById')->willReturn([
            'id'            => 3,
            'empresa_id'    => 8,
            'es_superadmin' => 0,
        ]);
        $users->expects($this->once())->method('companyIsActive')->with(8)->willReturn(false);

        $this->assertNull((new CodeIgniterActorContextProvider($users))->load(3));
    }
}
