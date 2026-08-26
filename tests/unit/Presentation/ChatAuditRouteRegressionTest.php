<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ChatAuditRouteRegressionTest extends TestCase
{
    public function testSuperadminPayloadExposesBackendGeneratedChatAuditUrl(): void
    {
        $source = file_get_contents(APPPATH . 'Presentation/AdministrationPayload.php');

        self::assertIsString($source);
        self::assertStringContainsString("'chatAudit'", $source);
        self::assertStringContainsString("base_url('mantenimiento/chatbot/auditoria')", $source);
    }

    public function testSuperadminPagePassesBackendUrlIntoChatAuditComponent(): void
    {
        $source = file_get_contents(ROOTPATH . 'frontend/src/pages/admin/SuperAdminDemoPage.vue');

        self::assertIsString($source);
        self::assertStringContainsString('apiUrl: data.chatAudit?.apiUrl', $source);
    }

    public function testListAndDetailShareTheSameResolvedApiUrl(): void
    {
        $source = file_get_contents(ROOTPATH . 'frontend/src/pages/admin/ChatAuditPage.vue');

        self::assertIsString($source);
        self::assertStringContainsString('`${apiUrl.value}?${qs(targetPage)}`', $source);
        self::assertStringContainsString('`${apiUrl.value}/${id}`', $source);
    }
}
