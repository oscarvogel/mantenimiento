<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Platform\GetPlatformModuleCatalog;
use PHPUnit\Framework\TestCase;

final class GetPlatformModuleCatalogTest extends TestCase
{
    /** @dataProvider maintenanceEntryPermissions */
    public function testListsMaintenanceForEachEffectiveEntryPermission(string $permission, string $landingPath): void
    {
        $actor = new ActorContext(12, 4, false, false, ['Consulta'], [$permission], [8]);

        $modules = (new GetPlatformModuleCatalog())->execute($actor);

        self::assertSame(['maintenance'], array_column($modules, 'key'));
        self::assertSame($landingPath, $modules[0]['landingPath']);
    }

    public static function maintenanceEntryPermissions(): iterable
    {
        yield 'equipment read' => ['equipos.ver', 'dashboard'];
        yield 'preventive plan read' => ['planes.ver', 'dashboard'];
        yield 'work order read' => ['ordenes.ver', 'dashboard'];
        yield 'assigned work' => ['ordenes.mi_trabajo', 'dashboard'];
        yield 'request creation' => ['solicitudes.crear', 'mantenimiento/solicitudes'];
        yield 'request review' => ['solicitudes.revisar', 'mantenimiento/solicitudes'];
        yield 'reports read' => ['reportes.ver', 'reportes'];
    }

    public function testDoesNotListMaintenanceWithoutAnEffectiveEntryPermission(): void
    {
        $actor = new ActorContext(12, 4, false, false, ['Administrador'], ['sucursales.ver'], [8]);

        self::assertSame([], (new GetPlatformModuleCatalog())->execute($actor));
    }

    public function testDoesNotTreatWriteOnlyPermissionsAsModuleEntry(): void
    {
        $actor = new ActorContext(12, 4, false, false, ['Responsable'], ['equipos.editar'], [8]);

        self::assertSame([], (new GetPlatformModuleCatalog())->execute($actor));
    }

    public function testSuperadministratorDoesNotInheritTenantModuleAccess(): void
    {
        $actor = new ActorContext(1, null, true, true, ['Superadministrador'], ['equipos.ver'], []);

        self::assertSame([], (new GetPlatformModuleCatalog())->execute($actor));
    }
}
