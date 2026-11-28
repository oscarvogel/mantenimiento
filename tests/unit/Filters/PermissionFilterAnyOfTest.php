<?php

declare(strict_types=1);

use App\Filters\PermissionFilter;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Con varios permisos (`permission:a,b`) alcanza con tener uno. Caso real: el técnico con solo
 * `ordenes.mi_trabajo` entra a "Mi trabajo" (`mantenimiento/ordenes`); la consulta ya le
 * muestra solo sus órdenes, pero la ruta exigía `ordenes.ver` y respondía 403.
 */
final class PermissionFilterAnyOfTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        session()->remove('actor_context');
        parent::tearDown();
    }

    /** @param list<string> $permissions */
    private function actingWith(array $permissions): void
    {
        session()->set('actor_context', [
            'user_id' => 7,
            'company_id' => 1,
            'super_admin' => false,
            'all_company_branches' => true,
            'roles' => ['tecnico'],
            'permissions' => $permissions,
            'branch_ids' => [],
        ]);
    }

    private function filtrar(array $arguments): mixed
    {
        return (new PermissionFilter())->before(service('request'), $arguments);
    }

    public function testConUnoDeLosPermisosPasa(): void
    {
        $this->actingWith(['ordenes.mi_trabajo']);
        self::assertNull($this->filtrar(['ordenes.ver', 'ordenes.mi_trabajo']));

        $this->actingWith(['ordenes.ver']);
        self::assertNull($this->filtrar(['ordenes.ver', 'ordenes.mi_trabajo']));
    }

    public function testSinNingunoResponde403(): void
    {
        $this->actingWith(['lecturas.cargar']);
        $response = $this->filtrar(['ordenes.ver', 'ordenes.mi_trabajo']);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testUnSoloPermisoSigueIgual(): void
    {
        $this->actingWith(['ordenes.mi_trabajo']);
        self::assertSame(403, $this->filtrar(['ordenes.ver'])->getStatusCode());
        self::assertNull($this->filtrar(['ordenes.mi_trabajo']));
    }

    public function testSinSesionOSinArgumentosResponde403(): void
    {
        self::assertSame(403, $this->filtrar(['ordenes.ver', 'ordenes.mi_trabajo'])->getStatusCode());

        $this->actingWith(['ordenes.ver']);
        self::assertSame(403, $this->filtrar([])->getStatusCode());
    }

    public function testLaListaDeOrdenesAdmiteMiTrabajo(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertStringContainsString(
            "\$routes->get('ordenes', 'WorkOrders::index', ['filter' => 'permission:ordenes.ver,ordenes.mi_trabajo']);",
            $routes,
        );
    }
}
