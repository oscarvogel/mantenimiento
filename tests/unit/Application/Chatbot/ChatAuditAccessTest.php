<?php

declare(strict_types=1);

use App\Application\Chatbot\Audit\ChatAuditAccess;
use App\Application\Identity\ActorContext;
use CodeIgniter\Test\CIUnitTestCase;

final class ChatAuditAccessTest extends CIUnitTestCase
{
    public function testSuperAdminGetsGlobalScope(): void
    {
        $actor = new ActorContext(1, null, true, false, [], [], []);

        $this->assertNull((new ChatAuditAccess())->companyScope($actor));
    }

    public function testCompanyAdministratorIsForcedToOwnCompany(): void
    {
        $actor = new ActorContext(
            10,
            7,
            false,
            true,
            ['Administrador'],
            [ChatAuditAccess::COMPANY_PERMISSION],
            [],
        );

        $this->assertSame(7, (new ChatAuditAccess())->companyScope($actor));
    }

    public function testUserWithoutAuditPermissionIsRejected(): void
    {
        $actor = new ActorContext(11, 7, false, false, ['Consulta'], ['chatbot.usar'], [2]);

        $this->expectException(DomainException::class);
        (new ChatAuditAccess())->companyScope($actor);
    }

    public function testTenantGlobalPermissionDoesNotTurnUserIntoSuperAdmin(): void
    {
        $actor = new ActorContext(
            12,
            7,
            false,
            true,
            ['Administrador'],
            [ChatAuditAccess::GLOBAL_PERMISSION, ChatAuditAccess::COMPANY_PERMISSION],
            [],
        );

        $this->assertSame(7, (new ChatAuditAccess())->companyScope($actor));
    }
}
