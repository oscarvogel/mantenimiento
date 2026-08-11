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

    public function testShowsPreventiveLibraryAsDirectNavigationItem(): void
    {
        $context = new GetAppShellContext(new AppShellReadModelFake());
        $actor = new ActorContext(7, 5, false, false, ['Responsable'], ['importaciones.ver'], [9]);

        $payload = (new AppShellPayload($context))->for($actor, 'preventive-library');
        $libraryItem = array_values(array_filter(
            $payload['navigation'],
            static fn (array $item): bool => $item['key'] === 'preventive-library',
        ));

        self::assertCount(1, $libraryItem);
        self::assertSame('Biblioteca preventiva', $libraryItem[0]['label']);
        self::assertStringEndsWith('/mantenimiento/importaciones/biblioteca', $libraryItem[0]['href']);
        self::assertTrue($libraryItem[0]['active']);
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
