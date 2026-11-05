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

        self::assertSame(
            ['trips', 'maintenance', 'fuel', 'tires', 'billing', 'management', 'reports', 'automations', 'ai'],
            array_column($modules, 'key'),
        );
        $maintenance = $this->module($modules, 'maintenance');
        self::assertSame($landingPath, $maintenance['landingPath']);
        self::assertSame('available', $maintenance['state']);
        self::assertSame('Operativo', $maintenance['status']);
        $fuel = $this->module($modules, 'fuel');
        self::assertSame('Sin implementación aún', $fuel['status']);
        self::assertSame('not_implemented', $fuel['state']);
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

    public function testTripsModuleLandsOnOperationalDashboardForTripReaders(): void
    {
        $actor = new ActorContext(12, 4, false, false, ['Consulta'], ['viajes.ver'], [8]);

        $modules = (new GetPlatformModuleCatalog())->execute($actor);
        $trips = array_values(array_filter($modules, static fn (array $module): bool => $module['key'] === 'trips'))[0];

        self::assertSame('viajes/operativos/dashboard', $trips['landingPath']);
        self::assertSame('available', $trips['state']);
        self::assertSame('Operativo', $trips['status']);
    }

    public function testTripsModuleLandsOnMastersWhenActorOnlyManagesCatalogs(): void
    {
        $actor = new ActorContext(12, 4, false, false, ['Consulta'], ['viajes.maestros.ver'], [8]);

        $modules = (new GetPlatformModuleCatalog())->execute($actor);
        $trips = array_values(array_filter($modules, static fn (array $module): bool => $module['key'] === 'trips'))[0];

        self::assertSame('viajes/maestros', $trips['landingPath']);
    }

    public function testKeepsMaintenanceVisibleButRestrictedWithoutAnEffectiveEntryPermission(): void
    {
        $actor = new ActorContext(12, 4, false, false, ['Administrador'], ['sucursales.ver'], [8]);

        $modules = (new GetPlatformModuleCatalog())->execute($actor);
        $maintenance = $this->module($modules, 'maintenance');

        self::assertSame('restricted', $maintenance['state']);
        self::assertNull($maintenance['landingPath']);
        self::assertSame('No habilitado para tu cuenta', $maintenance['status']);
        self::assertSame(9, count($modules));
    }

    public function testKeepsMaintenanceRestrictedForWriteOnlyPermissions(): void
    {
        $actor = new ActorContext(12, 4, false, false, ['Responsable'], ['equipos.editar'], [8]);

        $modules = (new GetPlatformModuleCatalog())->execute($actor);
        $maintenance = $this->module($modules, 'maintenance');

        self::assertSame('restricted', $maintenance['state']);
        self::assertNull($maintenance['landingPath']);
    }

    public function testSuperadministratorSeesMaintenanceRestrictedWithoutTenantAccess(): void
    {
        $actor = new ActorContext(1, null, true, true, ['Superadministrador'], ['equipos.ver'], []);

        $modules = (new GetPlatformModuleCatalog())->execute($actor);
        $maintenance = $this->module($modules, 'maintenance');

        self::assertSame('restricted', $maintenance['state']);
        self::assertNull($maintenance['landingPath']);
    }

    public function testEnablesManagementModuleForTheoreticalCostPermission(): void
    {
        $actor = new ActorContext(12, 4, false, false, ['Gerencial'], ['gerencial.costo_km.ver'], [8]);

        $modules = (new GetPlatformModuleCatalog())->execute($actor);
        $management = array_values(array_filter($modules, static fn (array $module): bool => $module['key'] === 'management'))[0];

        self::assertSame('gerencial/costo-teorico-km', $management['landingPath']);
        self::assertSame('available', $management['state']);
        self::assertSame('Operativo', $management['status']);
    }

    public function testKeepsManagementModuleDisabledWithoutItsPermission(): void
    {
        $actor = new ActorContext(12, 4, false, false, ['Consulta'], ['equipos.ver'], [8]);

        $modules = (new GetPlatformModuleCatalog())->execute($actor);
        $management = array_values(array_filter($modules, static fn (array $module): bool => $module['key'] === 'management'))[0];

        self::assertNull($management['landingPath']);
        self::assertSame('not_implemented', $management['state']);
        self::assertSame('Sin implementación aún', $management['status']);
    }

    public function testEnablesReportsModuleOnlyForTenantReportReaders(): void
    {
        $actor = new ActorContext(12, 4, false, false, ['Consulta'], ['reportes.ver'], [8]);

        $modules = (new GetPlatformModuleCatalog())->execute($actor);
        $reports = $this->module($modules, 'reports');

        self::assertSame('reportes', $reports['landingPath']);
        self::assertSame('available', $reports['state']);
        self::assertSame('Operativo', $reports['status']);
    }

    public function testKeepsReportsModuleRestrictedWithoutItsPermission(): void
    {
        $actor = new ActorContext(12, 4, false, false, ['Consulta'], ['equipos.ver'], [8]);

        $modules = (new GetPlatformModuleCatalog())->execute($actor);
        $reports = $this->module($modules, 'reports');

        self::assertNull($reports['landingPath']);
        self::assertSame('not_implemented', $reports['state']);
    }

    public function testSuperadministratorDoesNotGainTenantReportsAccess(): void
    {
        $actor = new ActorContext(1, null, true, true, ['Superadministrador'], ['reportes.ver'], []);

        $modules = (new GetPlatformModuleCatalog())->execute($actor);
        $reports = $this->module($modules, 'reports');

        self::assertNull($reports['landingPath']);
        self::assertSame('not_implemented', $reports['state']);
    }

    public function testEnablesAutomationsModuleOnlyForTenantReaders(): void
    {
        $actor = new ActorContext(12, 4, false, false, ['Responsable de mantenimiento'], ['automatizaciones.ver'], [8]);

        $modules = (new GetPlatformModuleCatalog())->execute($actor);
        $automations = $this->module($modules, 'automations');

        self::assertSame('automatizaciones', $automations['landingPath']);
        self::assertSame('available', $automations['state']);
        self::assertSame('Operativo', $automations['status']);
    }

    public function testKeepsAutomationsRestrictedWithoutItsPermission(): void
    {
        $actor = new ActorContext(12, 4, false, false, ['Consulta'], ['notificaciones.ver'], [8]);

        $modules = (new GetPlatformModuleCatalog())->execute($actor);
        $automations = $this->module($modules, 'automations');

        self::assertNull($automations['landingPath']);
        self::assertSame('not_implemented', $automations['state']);
    }

    public function testSuperadministratorDoesNotGainTenantAutomationsAccess(): void
    {
        $actor = new ActorContext(1, null, true, true, ['Superadministrador'], ['automatizaciones.ver'], []);

        $modules = (new GetPlatformModuleCatalog())->execute($actor);
        $automations = $this->module($modules, 'automations');

        self::assertNull($automations['landingPath']);
        self::assertSame('not_implemented', $automations['state']);
    }

    /**
     * @param list<array<string,mixed>> $modules
     * @return array<string,mixed>
     */
    private function module(array $modules, string $key): array
    {
        return array_values(array_filter(
            $modules,
            static fn (array $module): bool => $module['key'] === $key,
        ))[0];
    }
}
