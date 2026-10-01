<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Contrato del `.htaccess` de la raiz (webroot plano de Ferozo).
 *
 * Dos cosas se fijan acá, y las dos importan para produccion:
 *
 *  1. #339: el webroot de Ferozo tiene un directorio fisico llamado
 *     `mantenimiento` (resto de un despliegue antiguo). La regla generica del
 *     .htaccess solo reescribe a index.php cuando el destino no es archivo ni
 *     directorio, asi que /mantenimiento caia en el manejo de directorios de
 *     Apache y devolvia 403 generico sin llegar a la app. Estos tests fijan la
 *     regla y, sobre todo, su orden.
 *
 *  2. Exposicion de archivos de entorno: con `<Files ".env">` el `.env` ya
 *     devolvia 403 pero `.env.example` respondia 200, porque `<Files>` compara
 *     el nombre contra un GLOB y `.env` solo coincide con un archivo llamado
 *     exactamente asi. Los tests de abajo no buscan una cadena generica: leen
 *     los patrones `<FilesMatch>` reales del archivo y los evaluan contra los
 *     nombres que importan, para que la proteccion quede probada por
 *     comportamiento y no por texto.
 */
final class HtaccessMaintenanceRoutingTest extends TestCase
{
    /**
     * Nombres de archivo que el webroot nunca debe servir.
     *
     * @var list<string>
     */
    private const ENV_BASENAMES_PROTECTED = [
        '.env',
        '.env.example',
        '.env.local',
        '.env.production',
        '.env.backup',
    ];

    /**
     * Archivos legitimos que la regla NO debe bloquear: nombres que contienen
     * `.env` en otra posicion, o que arrancan parecido sin ser una variante.
     *
     * @var list<string>
     */
    private const ENV_BASENAMES_ALLOWED = [
        'mi.env.txt',
        'entorno.txt',
        '.environment',
        '.envrc',
        'index.php',
        'favicon.ico',
        'phpunit.dist.xml',
    ];

    private function htaccess(): string
    {
        // tests/unit/Platform -> tests/unit -> tests -> raiz
        return (string) file_get_contents(dirname(__DIR__, 3) . '/.htaccess');
    }

    /**
     * El `.htaccess` sin las lineas de comentario.
     *
     * Importa distinguir la configuracion real de la prosa que la explica: el
     * bloque de `.env` documenta en un comentario la directiva que se esta
     * reemplazando, y un `assertStringNotContainsString` sobre el archivo crudo
     * passaria por una mencion dentro de un comentario en lugar de detectar la
     * directiva.
     */
    private function htaccessDirectives(): string
    {
        $lines = preg_split('/\R/', $this->htaccess()) ?: [];

        $directives = array_filter(
            $lines,
            static fn (string $line): bool => preg_match('/^\s*#/', $line) !== 1,
        );

        return implode("\n", $directives);
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

    /**
     * Patrones `<FilesMatch "...">` declarados en el `.htaccess`.
     *
     * @return list<string>
     */
    private function filesMatchPatterns(): array
    {
        preg_match_all('/<FilesMatch\s+"([^"]+)"\s*>/', $this->htaccessDirectives(), $matches);

        return $matches[1];
    }

    /**
     * ¿Apache bloquearía este nombre de archivo por alguna seccion <FilesMatch>?
     *
     * Se replica la semántica de Apache: el patrón se evalúa contra el nombre
     * base del archivo, con las mismas reglas de delimitadores yFlags de PCRE.
     */
    private function isBlockedByFilesMatch(string $basename): bool
    {
        foreach ($this->filesMatchPatterns() as $pattern) {
            $matched = preg_match('#' . $pattern . '#', $basename);

            self::assertNotSame(
                false,
                $matched,
                sprintf('El patron <FilesMatch "%s"> no es una expresion regular valida.', $pattern),
            );

            if ($matched === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Regresion de seguridad: la proteccion tiene que ser una expresion regular.
     *
     * `<Files "...">` hace match con un GLOB, no con una regex: `<Files ".env">`
     * unicamente protege un archivo llamado exactamente `.env`, y por eso
     * `.env.example` quedaba expuesto. Volver a esa forma reintroduce el hueco.
     */
    public function testEnvProtectionIsRegexBasedAndNotTheExactNameGlob(): void
    {
        $directives = $this->htaccessDirectives();

        self::assertStringNotContainsString(
            '<Files ".env">',
            $directives,
            'La proteccion no puede volver a `<Files ".env">`: es un glob que solo cubre el nombre exacto y deja '
            . 'expuestas las variantes `.env.*`.',
        );

        self::assertNotEmpty(
            $this->filesMatchPatterns(),
            'El .htaccess debe declarar al menos una seccion <FilesMatch> para bloquear los archivos de entorno.',
        );
    }

    /**
     * La seccion que protege los archivos de entorno tiene que denegar acceso de
     * verdad; cambiar solo el nombre de la seccion no alcanza.
     */
    public function testEnvProtectionSectionActuallyDeniesAccess(): void
    {
        $matched = preg_match(
            '#<FilesMatch\s+"[^"]*\.env[^"]*"\s*>(?P<body>.*?)</FilesMatch>#s',
            $this->htaccessDirectives(),
            $matches,
        );

        self::assertSame(1, $matched, 'No se encontro la seccion <FilesMatch> que protege los archivos .env.');

        $body = (string) ($matches['body'] ?? '');

        self::assertMatchesRegularExpression(
            '/Require\s+all\s+denied|Deny\s+from\s+all/',
            $body,
            'La seccion de archivos de entorno debe negar el acceso (Require all denied o Deny from all).',
        );
    }

    /**
     * Comportamiento principal: `.env` y todas sus variantes quedan bloqueados.
     *
     * Este es el test que evita la regresion funcional. Si alguien vuelve a
     * dejar protegido solamente `.env`, `.env.example` deja de matchear y este
     * metodo falla nombrando el archivo exacto que quedo expuesto.
     *
     * @dataProvider envBasenamesProtectedProvider
     */
    public function testEnvFileVariantsAreBlocked(string $basename): void
    {
        self::assertTrue(
            $this->isBlockedByFilesMatch($basename),
            sprintf(
                '`%s` debe quedar bloqueado por una regla del .htaccess: es un archivo de entorno y si Apache lo '
                . 'sirve como archivo estatico se expone como contenido publico.',
                $basename,
            ),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function envBasenamesProtectedProvider(): iterable
    {
        foreach (self::ENV_BASENAMES_PROTECTED as $basename) {
            yield $basename => [$basename];
        }
    }

    /**
     * La regla no debe ser una francotiradora: archivos legitimos que solo
     * parecen un `.env` tienen que seguir serviéndose.
     *
     * @dataProvider envBasenamesAllowedProvider
     */
    public function testLegitimateFilesAreNotBlockedByTheEnvRule(string $basename): void
    {
        self::assertFalse(
            $this->isBlockedByFilesMatch($basename),
            sprintf(
                '`%s` no es un archivo de entorno y la regla no debe bloquearlo: un patron demasiado amplio '
                . 'deja fuera de servicio archivos reales de la aplicacion.',
                $basename,
            ),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function envBasenamesAllowedProvider(): iterable
    {
        foreach (self::ENV_BASENAMES_ALLOWED as $basename) {
            yield $basename => [$basename];
        }
    }

    /**
     * El cambio de la proteccion de archivos de entorno no puede haber tocado
     * el rewrite de `/mantenimiento`: esa regla sigue siendo la que manda al
     * front controller y tiene que seguir antes de la regla generica.
     */
    public function testEnvProtectionChangeLeftTheMaintenanceRoutingIntact(): void
    {
        $directives = $this->htaccessDirectives();

        self::assertStringContainsString(
            'RewriteRule ^mantenimiento(?:/.*)?$ index.php [L,NC]',
            $directives,
            'La regla de /mantenimiento no debe verse afectada por el bloqueo de archivos de entorno.',
        );

        self::assertStringContainsString(
            'RewriteRule ^(?:app|docs|frontend|home|scripts|tests|vendor|writable)(?:/|$) - [F,L,NC]',
            $directives,
            'El bloqueo de directorios de codigo fuente debe seguir presente.',
        );

        self::assertStringContainsString(
            'RewriteRule ^ index.php [L]',
            $directives,
            'El reescritura generica a index.php debe seguir presente.',
        );
    }
}
