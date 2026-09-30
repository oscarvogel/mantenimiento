<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * #339: el webroot de Ferozo tiene un directorio fisico llamado `mantenimiento`
 * (resto de un despliegue antiguo). La regla generica del .htaccess solo reescribe
 * a index.php cuando el destino no es archivo ni directorio, asi que /mantenimiento
 * caia en el manejo de directorios de Apache y devolvia 403 generico sin llegar a
 * la app. Estos tests fijan la regla y, sobre todo, su orden.
 */
final class HtaccessMaintenanceRoutingTest extends TestCase
{
    private function htaccess(): string
    {
        // tests/unit/Platform -> tests/unit -> tests -> raiz
        return (string) file_get_contents(dirname(__DIR__, 3) . '/.htaccess');
    }

    public function testMaintenanceNamespaceIsForcedToTheFrontController(): void
    {
        self::assertStringContainsString(
            'RewriteRule ^mantenimiento(?:/.*)?$ index.php [L,NC]',
            $this->htaccess(),
            'Las rutas bajo /mantenimiento deben llegar a index.php aunque exista el directorio.',
        );
    }

    public function testTheMaintenanceRuleComesBeforeTheGenericRewrite(): void
    {
        $htaccess = $this->htaccess();

        $maintenance = strpos($htaccess, '^mantenimiento(?:/.*)?$');
        $generic     = strpos($htaccess, 'RewriteCond %{REQUEST_FILENAME} !-d');

        self::assertIsInt($maintenance, 'Falta la regla de mantenimiento.');
        self::assertIsInt($generic, 'Falta la regla generica de reescritura.');
        self::assertLessThan(
            $generic,
            $maintenance,
            'La regla de mantenimiento debe ir antes de la condicion !-d; si va despues nunca se aplica.',
        );
    }

    public function testTheMaintenanceRuleDoesNotOpenTheSourceDirectoryBlock(): void
    {
        $htaccess = $this->htaccess();

        $maintenance = strpos($htaccess, '^mantenimiento(?:/.*)?$');
        $sourceBlock = strpos($htaccess, '(?:app|docs|frontend|home|scripts|tests|vendor|writable)');

        self::assertIsInt($maintenance);
        self::assertIsInt($sourceBlock);
        self::assertLessThan(
            $sourceBlock,
            $maintenance,
            'La regla de mantenimiento debe seguir sin tapar el bloqueo de codigo fuente.',
        );
    }

    public function testEnvironmentFileProtectionIsStillPresent(): void
    {
        self::assertStringContainsString('<Files ".env">', $this->htaccess());
    }
}
