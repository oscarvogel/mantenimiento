<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuditChangesRouteRegressionTest extends TestCase
{
    public function testSuperadminRouteIsDeclared(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertIsString($routes);
        self::assertStringContainsString("'auditoria', 'AuditChanges::index'", $routes);
    }

    public function testSuperadminPayloadExposesBackendGeneratedAuditUrl(): void
    {
        $source = file_get_contents(APPPATH . 'Presentation/AdministrationPayload.php');

        self::assertIsString($source);
        self::assertStringContainsString("'auditChanges'", $source);
        self::assertStringContainsString("base_url('superadmin/auditoria')", $source);
    }

    public function testSuperadminNavigationExposesAuditChangesSection(): void
    {
        $source = file_get_contents(APPPATH . 'Presentation/AppShellPayload.php');

        self::assertIsString($source);
        self::assertStringContainsString("'audit-changes'", $source);
        self::assertStringContainsString('superadmin?section=audit-changes', $source);
    }

    public function testSuperadminPageMountsAuditChangesWithBackendUrl(): void
    {
        $source = file_get_contents(ROOTPATH . 'frontend/src/pages/admin/SuperAdminDemoPage.vue');

        self::assertIsString($source);
        self::assertStringContainsString('apiUrl: data.auditChanges?.apiUrl', $source);
    }

    public function testAuditPageUsesTheResolvedApiUrl(): void
    {
        $source = file_get_contents(ROOTPATH . 'frontend/src/pages/admin/AuditChangesPage.vue');

        self::assertIsString($source);
        self::assertStringContainsString('`${apiUrl.value}?${qs(targetPage)}`', $source);
    }
}
