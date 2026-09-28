<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Contrato del hotfix de SOLO CONSULTA.
 *
 * Blindaje contra la reaparición de la funcionalidad de reclamos por
 * WhatsApp. Si alguien reintroduce una dependencia de mensajería en esta
 * pantalla, esta prueba falla antes del despliegue.
 */
final class ReadingControlReadOnlyContractTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function guardedFiles(): array
    {
        return [
            [APPPATH, 'Application/ReadingControl/ListReadingControl.php'],
            [APPPATH, 'Application/ReadingControl/ReadingControlQuery.php'],
            [APPPATH, 'Application/ReadingControl/ReadingControlFilter.php'],
            [APPPATH, 'Application/ReadingControl/EquipmentReadingControlRow.php'],
            [APPPATH, 'Controllers/ReadingControl.php'],
            [APPPATH, 'Presentation/ReadingControl/ReadingControlPayload.php'],
            [ROOTPATH, 'frontend/src/pages/operations/ReadingControlPage.vue'],
        ];
    }

    public function testNoReadingControlFileDependsOnWhatsApp(): void
    {
        foreach (self::guardedFiles() as [$base, $relative]) {
            $source = file_get_contents($base . $relative);

            self::assertIsString($source, $relative);
            foreach (['notificacion_whatsapp_entregas', 'whatsapp', 'WhatsApp', 'reclamo_manual_lectura', 'lastClaim', 'canClaim', 'ManualReadingClaimHandler'] as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $source,
                    sprintf('%s no debe contener "%s" en un hotfix de solo consulta.', $relative, $needle),
                );
            }
        }
    }

    public function testNoReadingControlFileReferencesTheClaimRouteOrPermission(): void
    {
        foreach (self::guardedFiles() as [$base, $relative]) {
            $source = file_get_contents($base . $relative);

            self::assertIsString($source, $relative);
            self::assertStringNotContainsString('lecturas.controlar', $source, $relative);
            self::assertStringNotContainsString('lecturas/control/reclamar', $source, $relative);
        }
    }

    public function testClaimRouteIsNotRegistered(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertIsString($routes);
        self::assertStringNotContainsString('lecturas/control/reclamar', $routes);
        self::assertStringContainsString("\$routes->get('lecturas/control', 'ReadingControl::index'", $routes);
    }

    public function testRouteIsProtectedByTheExistingEquipmentViewPermission(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertIsString($routes);
        self::assertStringContainsString("'permission:equipos.ver'", $routes);
    }

    public function testControllerExposesOnlyTheReadOnlyIndexAction(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/ReadingControl.php');

        self::assertIsString($controller);
        self::assertStringContainsString('public function index()', $controller);
        self::assertStringNotContainsString('function claim(', $controller);
    }

    public function testNavigationIsShownToUsersWithEquipmentViewPermission(): void
    {
        $shell = file_get_contents(APPPATH . 'Presentation/AppShellPayload.php');

        self::assertIsString($shell);
        self::assertStringContainsString("hasPermission('equipos.ver')", $shell);
        self::assertStringContainsString('Control de lecturas', $shell);
        self::assertStringNotContainsString('lecturas.controlar', $shell);
    }

    public function testNoNewMigrationIsAddedByThisHotfix(): void
    {
        $migrations = glob(APPPATH . 'Database/Migrations/*.php') ?: [];

        foreach ($migrations as $migration) {
            $source = (string) file_get_contents($migration);
            self::assertStringNotContainsString(
                'notificacion_whatsapp_entregas',
                $source,
                sprintf('La migración %s no debe tocar tablas de WhatsApp.', basename($migration)),
            );
        }
    }

    public function testPageIsRegisteredInTheOperationsPageRegistry(): void
    {
        $registry = file_get_contents(ROOTPATH . 'frontend/src/pages/operations/index.js');

        self::assertIsString($registry);
        self::assertStringContainsString("import ReadingControlPage from './ReadingControlPage.vue'", $registry);
        self::assertStringContainsString("'reading-control': ReadingControlPage", $registry);
    }
}
