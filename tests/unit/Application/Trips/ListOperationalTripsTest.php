<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\ListOperationalTrips;
use App\Application\Trips\OperationalTripFilters;
use App\Application\Trips\Port\OperationalTripReadModel;
use PHPUnit\Framework\TestCase;

final class ListOperationalTripsTest extends TestCase
{
    public function testScopesListToActorCompanyAndBranches(): void
    {
        $readModel = new ListTripsReadModelFake();
        $payload = (new ListOperationalTrips($readModel))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver', 'viajes.operar'], [10, 20]),
            new OperationalTripFilters(branchId: 10, status: 'PLANIFICADO'),
            2,
        );

        self::assertSame([5, [10, 20], 'PLANIFICADO'], $readModel->listQuery);
        self::assertSame(2, $payload['pagination']['page']);
        self::assertSame(25, $payload['pagination']['total']);
        self::assertTrue($payload['canOperate']);
        self::assertSame('Argentina', $payload['filters']['branchName']);
    }

    public function testReturnsEmptyPayloadWhenActorHasNoBranches(): void
    {
        $readModel = new ListTripsReadModelFake();
        $payload = (new ListOperationalTrips($readModel))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver'], []),
            new OperationalTripFilters(),
        );

        self::assertSame([], $payload['trips']);
        self::assertSame(0, $payload['pagination']['total']);
        self::assertSame([], $readModel->listQuery);
    }

    public function testRejectsActorWithoutReadPermission(): void
    {
        $this->expectException(DomainException::class);

        (new ListOperationalTrips(new ListTripsReadModelFake()))->execute(
            new ActorContext(1, 5, false, false, [], [], [10]),
            new OperationalTripFilters(),
        );
    }
}

final class ListTripsReadModelFake implements OperationalTripReadModel
{
    public array $listQuery = [];

    public function branches(int $companyId, ?array $branchIds): array
    {
        return [
            ['id' => 10, 'code' => 'AR', 'name' => 'Argentina'],
            ['id' => 20, 'code' => 'BR', 'name' => 'Brasil'],
        ];
    }

    public function list(int $companyId, ?array $branchIds, OperationalTripFilters $filters, int $page, int $perPage): array
    {
        $this->listQuery = [$companyId, $branchIds, $filters->status];

        return ['items' => [['id' => 1, 'numero' => 42]], 'total' => 25];
    }

    public function find(int $companyId, ?array $branchIds, int $tripId): ?array
    {
        return null;
    }
}
