<?php

declare(strict_types=1);

use App\Application\Audit\AuditAction;
use App\Application\Audit\AuditEntry;
use App\Application\Audit\AuditScope;
use CodeIgniter\Test\CIUnitTestCase;

final class AuditEntryTest extends CIUnitTestCase
{
    public function testCompanyScopeAcceptsCompanyWithoutBranch(): void
    {
        $entry = new AuditEntry(
            scope: AuditScope::EMPRESA,
            companyId: 7,
            branchId: null,
            action: AuditAction::USUARIO_ACTUALIZADO,
            entity: 'usuarios',
            entityId: '3',
            actorUserId: 9,
            actorCompanyId: 7,
            before: ['nombre' => 'Antes'],
            after: ['nombre' => 'Después'],
        );

        self::assertSame(AuditScope::EMPRESA, $entry->scope);
        self::assertSame(7, $entry->companyId);
    }

    public function testCompanyScopeRejectsBranch(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuditEntry(
            scope: AuditScope::EMPRESA,
            companyId: 7,
            branchId: 2,
            action: AuditAction::USUARIO_ACTUALIZADO,
            entity: 'usuarios',
            entityId: '3',
            actorUserId: 9,
            actorCompanyId: 7,
            before: [],
            after: ['nombre' => 'Después'],
        );
    }

    public function testBranchScopeRequiresCompanyAndBranch(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuditEntry(
            scope: AuditScope::SUCURSAL,
            companyId: 7,
            branchId: null,
            action: AuditAction::SUCURSAL_ACTUALIZADA,
            entity: 'sucursales',
            entityId: '2',
            actorUserId: 9,
            actorCompanyId: 7,
            before: [],
            after: ['nombre' => 'Sucursal'],
        );
    }

    public function testGlobalScopeRejectsAffectedCompany(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuditEntry(
            scope: AuditScope::GLOBAL,
            companyId: 7,
            branchId: null,
            action: AuditAction::EMPRESA_ACTUALIZADA,
            entity: 'empresas',
            entityId: '7',
            actorUserId: 99,
            actorCompanyId: null,
            before: [],
            after: ['estado' => 1],
        );
    }

    public function testRequiresBeforeOrAfterValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuditEntry(
            scope: AuditScope::EMPRESA,
            companyId: 7,
            branchId: null,
            action: AuditAction::USUARIO_ACTUALIZADO,
            entity: 'usuarios',
            entityId: '3',
            actorUserId: 9,
            actorCompanyId: 7,
        );
    }

    public function testRejectsInvalidActor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuditEntry(
            scope: AuditScope::EMPRESA,
            companyId: 7,
            branchId: null,
            action: AuditAction::USUARIO_ACTUALIZADO,
            entity: 'usuarios',
            entityId: '3',
            actorUserId: 0,
            actorCompanyId: 7,
            after: ['nombre' => 'Después'],
        );
    }
}
