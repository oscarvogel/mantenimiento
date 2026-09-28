<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Support\ReadingControl\PhpSource;

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
            // Se leen solo comentarios y docblocks: esos términos aparecen
            // legitimamente en la documentación que explica la eliminación.
            $code = PhpSource::codeOf($base . $relative);

            foreach (['notificacion_whatsapp_entregas', 'whatsapp', 'WhatsApp', 'reclamo_manual_lectura', 'lastClaim', 'canClaim', 'ManualReadingClaimHandler'] as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $code,
                    sprintf('%s no debe contener "%s" en un hotfix de solo consulta.', $relative, $needle),
                );
            }
        }
    }

    public function testNoReadingControlFileReferencesTheClaimRouteOrPermission(): void
    {
        foreach (self::guardedFiles() as [$base, $relative]) {
            $code = PhpSource::codeOf($base . $relative);

            self::assertStringNotContainsString('lecturas.controlar', $code, $relative);
            self::assertStringNotContainsString('lecturas/control/reclamar', $code, $relative);
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

    /**
     * El sidebar descarta los items que no pertenecen a un grupo conocido y los
     * acumula bajo "Más". Sin esta entrada, "Control de lecturas" no aparecía en
     * la sección "Operación".
     */
    public function testSidebarPlacesReadingControlInsideTheOperationGroup(): void
    {
        $sidebar = file_get_contents(ROOTPATH . 'frontend/src/components/AppSidebar.vue');

        self::assertIsString($sidebar);
        self::assertMatchesRegularExpression(
            "/key: 'operation'.*'reading-control'/",
            $sidebar,
            "El item reading-control debe estar dentro del grupo 'operation'.",
        );
    }

    public function testSidebarResolvesAnIconForReadingControl(): void
    {
        $shell = (string) file_get_contents(APPPATH . 'Presentation/AppShellPayload.php');
        $sidebar = (string) file_get_contents(ROOTPATH . 'frontend/src/components/AppSidebar.vue');

        self::assertSame(1, preg_match("/'reading-control'.*'(clipboard-list)'/", $shell));

        if (! preg_match("/'(clipboard-list)':/", $sidebar)) {
            self::assertSame(1, preg_match("/'/clipboard-check':/", $sidebar), 'El icono debe existir en el mapa de AppSidebar.');
        }
    }

    /**
     * El hotfix no debe agregar ni modificar migraciones.
     *
     * No se comprueba que las migraciones no mencionen la tabla de WhatsApp:
     * la migración que la CREA la menciona legitimamente. Lo que se exige es
     * que el diff contra main no toque el directorio de migraciones.
     */
    public function testHotfixAddsNoMigration(): void
    {
        $root = dirname(APPPATH, 2);
        $diff = shell_exec(
            sprintf('git -C %s diff --name-only origin/main...HEAD -- app/Database/Migrations 2>/dev/null', escapeshellarg($root)),
        );

        $changed = array_values(array_filter(array_map('trim', explode("\n", (string) $diff))));

        self::assertSame([], $changed, 'El hotfix no debe agregar ni modificar migraciones.');
    }

    public function testPageIsRegisteredInTheOperationsPageRegistry(): void
    {
        $registry = file_get_contents(ROOTPATH . 'frontend/src/pages/operations/index.js');

        self::assertIsString($registry);
        self::assertStringContainsString("import ReadingControlPage from './ReadingControlPage.vue'", $registry);
        self::assertStringContainsString("'reading-control': ReadingControlPage", $registry);
    }
}
