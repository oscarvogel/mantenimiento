<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * #339: la denegacion por permisos devuelve una pantalla 403 en español y
 * coherente con la app, en vez del texto plano.
 *
 * Se fija tambien que la pantalla no filtre informacion interna: no puede
 * mencionar el permiso que falto ni la ruta solicitada.
 */
final class PermissionFilterForbiddenResponseTest extends CIUnitTestCase
{
    private function filter(): string
    {
        return (string) file_get_contents(APPPATH . 'Filters/PermissionFilter.php');
    }

    private function view(): string
    {
        return (string) file_get_contents(APPPATH . 'Views/errors/forbidden.php');
    }

    public function testDeniedAccessReturnsARealHtml403(): void
    {
        $filter = $this->filter();

        self::assertStringContainsString('setStatusCode(403)', $filter, 'La denegacion debe responder 403.');
        self::assertStringContainsString("setContentType('text/html', 'UTF-8')", $filter);
        self::assertStringContainsString("view('errors/forbidden'", $filter);
    }

    public function testFilterAllowsTheRequestWhenThePermissionIsGranted(): void
    {
        self::assertStringContainsString(
            'return null;',
            $this->filter(),
            'Con permiso asignado el filtro no debe responder nada.',
        );
    }

    public function testForbiddenViewEscapesTheMessageAndIsInSpanish(): void
    {
        $view = $this->view();

        self::assertStringContainsString('<?= esc($message', $view, 'El mensaje debe escaparse.');
        self::assertStringContainsString('lang="es"', $view);
        self::assertStringContainsString('Acceso restringido', $view);
    }

    public function testForbiddenViewOffersASafeWayBack(): void
    {
        // dashboard solo exige auth, asi que es seguro para cualquier usuario activo.
        self::assertStringContainsString("base_url('dashboard')", $this->view());
    }

    public function testForbiddenViewDoesNotLeakInternalInformation(): void
    {
        $view   = $this->view();
        $filter = $this->filter();

        foreach (['APPPATH', 'PermissionFilter', 'Routes.php', 'ActorContext'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $view,
                "La pantalla 403 no debe exponer detalles internos ({$forbidden}).",
            );
        }

        // El mensaje mostrado es un literal, no se arma con el permiso evaluado:
        // asi un usuario no puede enumerar que le falta.
        self::assertStringContainsString("['message' => \$message]", $filter);
        self::assertStringNotContainsString("'message' => \$permission", $filter);
        self::assertStringNotContainsString('$permission .', $filter);
    }
}
