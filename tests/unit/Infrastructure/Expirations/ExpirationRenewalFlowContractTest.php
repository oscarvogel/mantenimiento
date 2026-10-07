<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExpirationRenewalFlowContractTest extends TestCase
{
    public function testRenewalRouteAndControllerPreserveHistoricalVersion(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $controller = file_get_contents(APPPATH . 'Controllers/Expirations.php');

        self::assertIsString($routes);
        self::assertIsString($controller);
        self::assertStringContainsString(
            "\$routes->post('vencimientos/(:num)/renovar', 'Expirations::renew/\$1');",
            $routes,
        );
        self::assertStringContainsString('public function renew(int $expirationId): RedirectResponse', $controller);
        self::assertStringContainsString("->where('v.empresa_id', \$companyId)", $controller);
        self::assertStringContainsString("->where('v.activo', 1)", $controller);
        self::assertStringContainsString("'origen' => 'MANUAL'", $controller);
        self::assertStringContainsString("'importacion_id' => null", $controller);
        self::assertStringContainsString('CodeIgniterExpirationActiveVersionManager', $controller);
        self::assertStringContainsString('->transBegin()', $controller);
        self::assertStringContainsString('->transRollback()', $controller);
    }

    public function testReadModelExposesRenewalContextWithoutLosingSubjectLink(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Expirations/CodeIgniterExpirationReadModel.php');

        self::assertIsString($source);
        self::assertStringContainsString('t.requiere_documento', $source);
        self::assertStringContainsString("'requiresDocument'", $source);
        self::assertStringContainsString("'renewUrl'", $source);
        self::assertStringContainsString("'subjectUrl'", $source);
    }

    public function testListUsesRenewModalAndKeepsFullRecordAsSecondaryAction(): void
    {
        $page = file_get_contents(ROOTPATH . 'frontend/src/pages/operations/ExpirationsIndexPage.vue');
        $modal = file_get_contents(ROOTPATH . 'frontend/src/pages/operations/components/RenewExpirationModal.vue');

        self::assertIsString($page);
        self::assertIsString($modal);
        self::assertStringContainsString("import RenewExpirationModal", $page);
        self::assertStringContainsString('canEditEquipment', $page);
        self::assertStringContainsString('canEditEmployees', $page);
        self::assertStringContainsString('Renovar', $page);
        self::assertStringContainsString('Ver ficha', $page);
        self::assertStringContainsString(':return-to="returnTo"', $page);

        self::assertStringContainsString('Se creará una nueva vigencia', $modal);
        self::assertStringContainsString('name="return_to"', $modal);
        self::assertStringContainsString('name="fecha_vencimiento"', $modal);
        self::assertStringContainsString(':min="minimumExpirationDate || undefined"', $modal);
        self::assertStringContainsString(':required="expiration.requiresDocument"', $modal);
        self::assertStringContainsString('Guardar renovación', $modal);
    }
}
