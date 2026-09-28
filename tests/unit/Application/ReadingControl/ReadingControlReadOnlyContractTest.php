<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Support\ReadingControl\PhpSource;

/**
 * Contrato del control de lecturas con reclamo manual por WhatsApp.
 *
 * Este hotfix dejó de ser estrictamente de solo consulta, así que el contrato
 * ya no puede exigir "cero mención a WhatsApp". Lo que sí debe seguir siendo
 * cierto, y es lo que estos tests blindan:
 *
 *  - nunca se usan las columnas `created_by` / `deleted_at` de
 *    notificacion_whatsapp_entregas, porque NO existen en el esquema;
 *  - el envío es una acción explícita del operador, nunca automático;
 *  - el destinatario lo resuelve el servidor, no el navegador;
 *  - se respeta el piloto y la configuración por empresa existentes;
 *  - el índice sigue siendo una consulta de solo lectura;
 *  - el acceso al reclamo exige un permiso real.
 */
final class ReadingControlReadOnlyContractTest extends TestCase
{
    /**
     * Archivos de runtime que este hotfix agrega o modifica.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function guardedFiles(): array
    {
        return [
            [APPPATH, 'Application/ReadingControl/ListReadingControl.php'],
            [APPPATH, 'Application/ReadingControl/ReadingControlQuery.php'],
            [APPPATH, 'Application/ReadingControl/ReadingControlFilter.php'],
            [APPPATH, 'Application/ReadingControl/EquipmentReadingControlRow.php'],
            [APPPATH, 'Application/ReadingControl/ClaimReadingReminder.php'],
            [APPPATH, 'Controllers/ReadingControl.php'],
            [APPPATH, 'Presentation/ReadingControl/ReadingControlPayload.php'],
            [ROOTPATH, 'frontend/src/pages/operations/ReadingControlPage.vue'],
        ];
    }

    /**
     * Regresión del HTTP 500 original: la tabla de entregas nunca tuvo
     * `created_by` ni `deleted_at`. Usarlas produce ERROR 1054 en MariaDB.
     */
    public function testNeverUsesColumnsThatDoNotExistInTheDeliveryTable(): void
    {
        foreach (self::guardedFiles() as [$base, $relative]) {
            $code = PhpSource::codeOf($base . $relative);

            // Nunca referenciadas con alias de la tabla de entregas.
            self::assertStringNotContainsString('n.created_by', $code, $relative);
            self::assertStringNotContainsString('n.deleted_at', $code, $relative);
        }

        // Y el insert de la entrega no puede incluir esas claves. Se aísla el
        // bloque `table('notificacion_whatsapp_entregas')->ignore(true)->insert([...])`
        // para no confundirlo con los `deleted_at` legitimos de empresas,
        // equipos y empleados, que sí existen.
        $reminder = PhpSource::codeOf(APPPATH . 'Application/ReadingControl/ClaimReadingReminder.php');
        $matches = [];
        self::assertSame(
            1,
            preg_match(
                "/table\('notificacion_whatsapp_entregas'\)->ignore\(true\)->insert\(\[(.*?)\]\);/s",
                $reminder,
                $matches,
            ),
            'No se pudo aislar el insert de la entrega.',
        );
        self::assertStringNotContainsString("'created_by'", $matches[1]);
        self::assertStringNotContainsString("'deleted_at'", $matches[1]);
    }

    public function testClaimRouteIsPostOnlyAndRequiresAPermission(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertStringContainsString(
            "\$routes->post('lecturas/control/reclamar', 'ReadingControl::claim'",
            $routes,
        );
        self::assertStringNotContainsString(
            "\$routes->get('lecturas/control/reclamar'",
            $routes,
            'El reclamo no puede exponerse por GET.',
        );
        self::assertMatchesRegularExpression(
            "/post\('lecturas\/control\/reclamar'.*permission:/",
            $routes,
            'La ruta del reclamo debe exigir un permiso.',
        );
    }

    /**
     * `lecturas.controlar` no existe en migraciones ni en InitialSeeder:
     * usarlo dejaria la pantalla inaccesible.
     */
    public function testDoesNotUseAPermissionThatDoesNotExist(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $controller = PhpSource::codeOf(APPPATH . 'Controllers/ReadingControl.php');

        self::assertStringNotContainsString('lecturas.controlar', $routes);
        self::assertStringNotContainsString('lecturas.controlar', $controller);

        // El permiso usado debe existir de verdad en el seeder.
        $seeder = (string) file_get_contents(APPPATH . 'Database/Seeds/InitialSeeder.php');
        self::assertStringContainsString("'lecturas.cargar'", $seeder);
    }

    public function testClaimNeverSendsAutomatically(): void
    {
        $controller = PhpSource::codeOf(APPPATH . 'Controllers/ReadingControl.php');
        $reminder = PhpSource::codeOf(APPPATH . 'Application/ReadingControl/ClaimReadingReminder.php');

        // El envío solo puede alcanzarse desde la acción claim().
        self::assertStringContainsString('public function claim(', $controller);
        self::assertStringNotContainsString('autoenvio', $reminder);
        self::assertStringNotContainsString('enviarAutomaticamente', $reminder);

        // index() no debe invocar el caso de uso de reclamo: abrir o recargar la
        // pantalla jamás puede disparar un WhatsApp.
        self::assertSame(
            0,
            substr_count($this->methodBody($controller, 'index'), 'claimReadingReminder'),
            'index() no debe poder enviar un reclamo.',
        );
    }

    /**
     * Extrae el cuerpo de un método público del código sin comentarios.
     */
    private function methodBody(string $source, string $method): string
    {
        $pattern = '/public function ' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{(.*?)\n    \}/s';
        $matches = [];
        self::assertSame(1, preg_match($pattern, $source, $matches), 'No se pudo aislar ' . $method . '().');

        return $matches[1];
    }

    public function testDestinationIsResolvedServerSideFromTheEquipment(): void
    {
        $reminder = PhpSource::codeOf(APPPATH . 'Application/ReadingControl/ClaimReadingReminder.php');

        // empresa -> equipo -> asignacion vigente -> chofer -> telefono.
        // El chofer vigente se resuelve con la constante SQL ACTIVE_DRIVER_SQL.
        self::assertStringContainsString("a.rol = 'CHOFER'", $reminder);
        self::assertStringContainsString('a.fecha_hasta IS NULL', $reminder);
        self::assertStringContainsString('emp.activo = 1', $reminder);
        self::assertStringContainsString('emp.deleted_at IS NULL', $reminder);
        self::assertStringContainsString('normalizePhone', $reminder);
        self::assertStringContainsString("'empleado_id' => $employeeId", $reminder);
    }

    public function testCrossCompanyIsolationIsEnforcedInTheQuery(): void
    {
        $reminder = PhpSource::codeOf(APPPATH . 'Application/ReadingControl/ClaimReadingReminder.php');

        self::assertStringContainsString("->where('e.empresa_id', \$companyId)", $reminder);
        self::assertStringContainsString('a2.empresa_id = a.empresa_id', $reminder);
        self::assertStringContainsString('emp.empresa_id = drv.empresa_id', $reminder);
        self::assertStringContainsString('l2.empresa_id = le.empresa_id', $reminder);
    }

    public function testPilotAndCompanyConfigurationAreRespected(): void
    {
        $reminder = PhpSource::codeOf(APPPATH . 'Application/ReadingControl/ClaimReadingReminder.php');

        self::assertStringContainsString('whatsapp_pilot_enabled', $reminder);
        self::assertStringContainsString('whatsapp_pilot_phone', $reminder);
        self::assertStringContainsString('notificaciones_whatsapp_habilitadas', $reminder);
        self::assertStringContainsString('whatsapp_instance_id', $reminder);
        // El destino en piloto NUNCA es el teléfono real.
        self::assertStringContainsString('$pilotEnabled ? $pilotPhone : $realPhone', $reminder);
    }

    public function testReusesTheExistingPublicLinkMechanism(): void
    {
        $reminder = PhpSource::codeOf(APPPATH . 'Application/ReadingControl/ClaimReadingReminder.php');

        self::assertStringContainsString('CodeIgniterPublicEquipmentTokenRepository', $reminder);
        self::assertStringContainsString('ensureActivePlainTokenForEquipment', $reminder);
        self::assertStringContainsString('resolveActiveToken', $reminder);
        self::assertStringContainsString("base_url('mantenimiento/publico/equipo/'", $reminder);
    }

    public function testIndexRemainsAReadOnlyQuery(): void
    {
        $useCase = PhpSource::codeOf(APPPATH . 'Application/ReadingControl/ListReadingControl.php');

        self::assertStringNotContainsString('sendText', $useCase);
        self::assertStringNotContainsString('insert(', $useCase);
        self::assertStringNotContainsString('->update(', $useCase);
    }

    public function testNavigationStillUsesTheEquipmentViewPermission(): void
    {
        $shell = (string) file_get_contents(APPPATH . 'Presentation/AppShellPayload.php');

        self::assertStringContainsString("hasPermission('equipos.ver')", $shell);
        self::assertStringContainsString('Control de lecturas', $shell);
        self::assertStringNotContainsString('lecturas.controlar', $shell);
    }

    public function testHotfixAddsNoMigration(): void
    {
        $root = dirname(APPPATH, 2);
        $diff = shell_exec(
            sprintf('git -C %s diff --name-only origin/main...HEAD -- app/Database/Migrations 2>/dev/null', escapeshellarg($root)),
        );

        $changed = array_values(array_filter(array_map('trim', explode("\n", (string) $diff))));

        self::assertSame([], $changed, 'Este hotfix no debe agregar ni modificar migraciones.');
    }

    public function testSidebarPlacesReadingControlInsideTheOperationGroup(): void
    {
        $sidebar = (string) file_get_contents(ROOTPATH . 'frontend/src/components/AppSidebar.vue');

        self::assertMatchesRegularExpression(
            "/key: 'operation'.*'reading-control'/",
            $sidebar,
        );
    }
}
