<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Dashboard;

use App\Application\Dashboard\GetGlobalDashboard;
use App\Application\Dashboard\Port\GlobalDashboardReadModel;
use App\Application\Identity\ActorContext;
use DomainException;
use PHPUnit\Framework\TestCase;

final class GetGlobalDashboardTest extends TestCase
{
    public function testSuperadminCanReadGlobalDashboard(): void
    {
        $expected = ['metrics' => ['companiesActive' => 3]];
        $readModel = new class($expected) implements GlobalDashboardReadModel {
            public function __construct(private readonly array $payload)
            {
            }

            public function fetch(): array
            {
                return $this->payload;
            }
        };

        $actor = new ActorContext(1, null, true, true, ['Superadministrador'], [], []);
        $result = (new GetGlobalDashboard($readModel))->execute($actor);

        self::assertSame($expected, $result);
    }

    public function testTenantActorCannotReadGlobalDashboard(): void
    {
        $readModel = new class implements GlobalDashboardReadModel {
            public function fetch(): array
            {
                return [];
            }
        };

        $actor = new ActorContext(2, 10, false, true, ['Administrador'], [], []);

        $this->expectException(DomainException::class);
        (new GetGlobalDashboard($readModel))->execute($actor);
    }
}
