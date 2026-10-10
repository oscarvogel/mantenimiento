<?php

declare(strict_types=1);

use App\Application\AppShell\GetAppShellContext;
use App\Application\AppShell\Port\AppShellReadModel;
use App\Application\Identity\ActorContext;
use App\Presentation\AppShellPayload;
use PHPUnit\Framework\TestCase;

final class GetAppShellContextTest extends TestCase
{
    public function testBuildsTenantIdentityAndScopedBranches(): void
    {
        $actor = new ActorContext(7, 5, false, false, ['Consulta'], ['equipos.ver'], [9]);
        $result = (new GetAppShellContext(new AppShellReadModelFake()))->execute($actor);

        self::assertSame('tenant', $result['mode']);
        self::assertSame('Transportes Demo', $result['company']['name']);
        self::assertSame([['id' => 9, 'name' => 'Central']], $result['company']['branches']);
        self::assertSame(['Consulta'], $result['user']['roles']);
        self::assertFalse($result['user']['isSuperAdmin']);
    }

    public function testBuildsGlobalContextWithoutTenantData(): void
    {
        $actor = new ActorContext(1, null, true, true, ['Superadministrador'], [], []);
        $result = (new GetAppShellContext(new AppShellReadModelFake()))->execute($actor);

        self::assertSame('global', $result['mode']);
        self::assertSame('Administración global', $result['company']['name']);
        self::assertSame([], $result['company']['branches']);
        self::assertTrue($result['user']['isSuperAdmin']);
    }

    public function testShowsPreventivePlansNavigationOnlyWithReadPermission(): void
    {
        $context = new GetAppShellContext(new AppShellReadModelFake());
        $withPlans = new ActorContext(7, 5, false, false, ['Responsable'], ['planes.ver'], [9]);
        $withoutPlans = new ActorContext(8, 5, false, false, ['Consulta'], ['equipos.ver'], [9]);

        $visible = (new AppShellPayload($context))->for($withPlans, 'plans');
        $hidden = (new AppShellPayload($context))->for($withoutPlans, 'equipment');

        $plansItem = array_values(array_filter(
            $visible['navigation'],
            static fn (array $item): bool => $item['key'] === 'plans',
        ));

        self::assertCount(1, $plansItem);
        self::assertSame('Planes preventivos', $plansItem[0]['label']);
        self::assertStringEndsWith('/mantenimiento/planes', $plansItem[0]['href']);
        self::assertTrue($plansItem[0]['active']);
        self::assertNotContains('plans', array_column($hidden['navigation'], 'key'));
    }

    public function testShowsMaintenanceServicesAsDirectNavigationItem(): void
    {
        $context = new GetAppShellContext(new AppShellReadModelFake());
        $actor = new ActorContext(7, 5, false, false, ['Responsable'], ['planes.ver'], [9]);

        $payload = (new AppShellPayload($context))->for($actor, 'services');
        $servicesItem = array_values(array_filter(
            $payload['navigation'],
            static fn (array $item): bool => $item['key'] === 'services',
        ));

        self::assertCount(1, $servicesItem);
        self::assertSame('Servicios de mantenimiento', $servicesItem[0]['label']);
        self::assertStringEndsWith('/mantenimiento/servicios', $servicesItem[0]['href']);
        self::assertTrue($servicesItem[0]['active']);
    }

    public function testShowsRegisterKmHoursOnlyWithReadingLoadPermission(): void
    {
        $context = new GetAppShellContext(new AppShellReadModelFake());
        $reader = new ActorContext(7, 5, false, false, ['Operador'], ['lecturas.cargar'], [9]);
        $equipmentOnly = new ActorContext(8, 5, false, false, ['Consulta'], ['equipos.ver'], [9]);

        $visible = (new AppShellPayload($context))->for($reader, 'quick-readings');
        $hidden = (new AppShellPayload($context))->for($equipmentOnly, 'equipment');

        $readingItem = array_values(array_filter(
            $visible['navigation'],
            static fn (array $item): bool => $item['key'] === 'quick-readings',
        ));

        self::assertCount(1, $readingItem);
        self::assertSame('Registrar km/horas', $readingItem[0]['label']);
        self::assertStringEndsWith('/mantenimiento/lecturas/rapidas', $readingItem[0]['href']);
        self::assertTrue($readingItem[0]['active']);
        self::assertNotContains('quick-readings', array_column($hidden['navigation'], 'key'));
    }

    public function testShowsFleetTelemetryForEquipmentReaders(): void
    {
        $context = new GetAppShellContext(new AppShellReadModelFake());
        $reader = new ActorContext(7, 5, false, false, ['Consulta'], ['equipos.ver'], [9]);
        $withoutEquipmentRead = new ActorContext(8, 5, false, false, ['Solicitante'], ['solicitudes.crear'], [9]);

        $visible = (new AppShellPayload($context))->for($reader, 'fleet-telemetry');
        $hidden = (new AppShellPayload($context))->for($withoutEquipmentRead, 'equipment');
        $item = array_values(array_filter($visible['navigation'], static fn (array $entry): bool => $entry['key'] === 'fleet-telemetry'));

        self::assertCount(1, $item);
        self::assertSame('Telemetría de flota', $item[0]['label']);
        self::assertStringEndsWith('/mantenimiento/telemetria', $item[0]['href']);
        self::assertTrue($item[0]['active']);
        self::assertNotContains('fleet-telemetry', array_column($hidden['navigation'], 'key'));
    }

    /**
     * #353: la entrada del centro de Maestros se llama "Maestros" y no se
     * duplica con la entrada independiente de Tipos de vencimiento.
     */
    public function testMastersCenterHasOneCoherentEntryAlongsideExpirationTypes(): void
    {
        $context = new GetAppShellContext(new AppShellReadModelFake());
        $actor = new ActorContext(7, 5, false, false, ['Responsable'], ['equipos.editar'], [9]);

        $payload = (new AppShellPayload($context))->for($actor, 'masters-equipment');
        $keys = array_column($payload['navigation'], 'key');

        self::assertCount(1, array_keys($keys, 'masters-equipment', true), 'No puede haber dos entradas al centro de Maestros.');
        self::assertCount(1, array_keys($keys, 'masters-expirations', true), 'Tipos de vencimiento conserva su entrada propia.');

        $center = array_values(array_filter(
            $payload['navigation'],
            static fn (array $item): bool => $item['key'] === 'masters-equipment',
        ));
        self::assertSame('Maestros', $center[0]['label']);
        self::assertStringEndsWith('/mantenimiento/maestros/equipos', $center[0]['href']);

        $expirations = array_values(array_filter(
            $payload['navigation'],
            static fn (array $item): bool => $item['key'] === 'masters-expirations',
        ));
        self::assertSame('Tipos de vencimiento', $expirations[0]['label']);
        self::assertStringEndsWith('/mantenimiento/maestros/vencimientos', $expirations[0]['href']);
    }

    /**
     * #353: empleados.editar sin equipos.editar debe conservar el acceso a
     * Tipos de vencimiento. La ruta maestros/equipos exige equipos.editar, asi
     * que sacar esta entrada del sidebar le dejaria sin ninguna via.
     */
    public function testEmployeeEditorWithoutEquipmentEditorKeepsExpirationTypesAccess(): void
    {
        $context = new GetAppShellContext(new AppShellReadModelFake());
        $actor = new ActorContext(9, 5, false, false, ['RRHH'], ['empleados.editar'], [9]);

        $payload = (new AppShellPayload($context))->for($actor, 'masters-expirations');
        $keys = array_column($payload['navigation'], 'key');

        self::assertContains('masters-expirations', $keys, 'Sin equipos.editar, esta es la unica via a Tipos de vencimiento.');
        self::assertNotContains('masters-equipment', $keys, 'El centro de Maestros exige equipos.editar.');
    }
}

final class AppShellReadModelFake implements AppShellReadModel
{
    public function fetch(ActorContext $actor): array
    {
        if ($actor->isSuperAdmin()) {
            return [
                'user' => ['nombre' => 'Super Admin', 'email' => 'super@example.test'],
                'company' => null,
                'branches' => [],
            ];
        }

        return [
            'user' => ['nombre' => 'Usuario Demo', 'email' => 'user@example.test'],
            'company' => ['razon_social' => 'Transportes Demo SA', 'nombre_fantasia' => 'Transportes Demo'],
            'branches' => [['id' => 9, 'nombre' => 'Central']],
        ];
    }
}
