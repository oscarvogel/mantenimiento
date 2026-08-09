<?php

declare(strict_types=1);

use App\Application\AppShell\GetAppShellContext;
use App\Application\AppShell\Port\AppShellReadModel;
use App\Application\Identity\ActorContext;
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
