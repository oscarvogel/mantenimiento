<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regresión del segundo HTTP 500 en producción:
 *
 *   ErrorException: Undefined property: App\Controllers\ReadingControl::$database
 *
 * `CodeIgniter\Controller` NO implementa `__get()`. Solo expone `$request`,
 * `$response`, `$logger`, `$helpers`, `$forceHTTPS` y `$validator`. Cualquier
 * otra propiedad launching `$this->X` debe existir en la propia clase o en
 * `BaseController`.
 */
final class ReadingControlControllerContractTest extends TestCase
{
    /**
     * Propiedades que CodeIgniter\Controller declara realmente.
     *
     * @var list<string>
     */
    private const CI4_PROPERTIES = ['helpers', 'request', 'response', 'logger', 'forceHTTPS', 'validator'];

    private function controllerSource(): string
    {
        $source = file_get_contents(APPPATH . 'Controllers/ReadingControl.php');
        self::assertIsString($source);

        return $source;
    }

    /**
     * @return list<string>
     */
    private function usedThisProperties(string $source): array
    {
        preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)/', $source, $matches);
        $used = array_values(array_unique($matches[1]));

        $base = (string) file_get_contents(APPPATH . 'Controllers/BaseController.php');
        $own = (string) file_get_contents(APPPATH . 'Controllers/ReadingControl.php');

        $declared = array_merge(
            self::CI4_PROPERTIES,
            $this->declaredMembers($base),
            $this->declaredMembers($own),
        );

        return array_values(array_diff($used, array_unique($declared)));
    }

    /**
     * @return list<string>
     */
    private function declaredMembers(string $source): array
    {
        preg_match_all('/(?:private|protected|public)\s+(?:readonly\s+)?(?:function|[\w\\\\|?]+\s+\$)\s*([A-Za-z_][A-Za-z0-9_]*)/', $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function testControllerDoesNotUsePropertiesThatDoNotExist(): void
    {
        $undefined = $this->usedThisProperties($this->controllerSource());

        self::assertSame(
            [],
            $undefined,
            'CodeIgniter\Controller no tiene __get(); estas propiedades no existen: ' . implode(', ', $undefined),
        );
    }

    public function testControllerGetsTheConnectionThroughDbConnect(): void
    {
        $source = $this->controllerSource();

        self::assertStringContainsString('db_connect()', $source);
        self::assertStringNotContainsString('$this->database', $source);
        self::assertStringNotContainsString('$this->db->', $source);
    }

    public function testControllerExposesOnlyTheReadOnlyIndexAction(): void
    {
        preg_match_all('/public function ([A-Za-z_][A-Za-z0-9_]*)/', $this->controllerSource(), $matches);
        $publicMethods = array_values(array_diff($matches[1], ['__construct', '__get', '__set']));

        foreach ($publicMethods as $method) {
            if (in_array($method, ['initController', 'forceHTTPS', 'cachePage', 'validate', 'validateData'], true)) {
                continue;
            }
            self::assertSame('index', $method, 'El controlador solo debe exponer la acción index().');
        }
    }
}
