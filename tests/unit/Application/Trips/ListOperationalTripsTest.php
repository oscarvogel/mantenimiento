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
            10,
        );

        self::assertSame([5, [10, 20], 'PLANIFICADO'], $readModel->listQuery);
        self::assertSame(2, $payload['pagination']['page']);
        self::assertSame(25, $payload['pagination']['total']);
        self::assertTrue($payload['canOperate']);
        self::assertSame('Argentina', $payload['filters']['branchName']);
    }

    public function testUsesTwentyFivePerPageByDefaultAndReportsTheVisibleRange(): void
    {
        $readModel = new ListTripsReadModelFake(total: 346);
        $payload = (new ListOperationalTrips($readModel))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver'], [10]),
            new OperationalTripFilters(),
        );

        self::assertSame([[1, 25]], $readModel->pageRequests);
        self::assertSame(25, $payload['pagination']['perPage']);
        self::assertSame([10, 25, 50, 100], $payload['pagination']['perPageOptions']);
        self::assertSame(14, $payload['pagination']['pages']);
        self::assertSame(1, $payload['pagination']['from']);
        self::assertSame(25, $payload['pagination']['to']);
        self::assertFalse($payload['hasActiveFilters']);
    }

    public function testTheLastPageShowsTheRemainingTrips(): void
    {
        $readModel = new ListTripsReadModelFake(total: 26);
        $payload = (new ListOperationalTrips($readModel))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver'], [10]),
            new OperationalTripFilters(),
            2,
            25,
        );

        self::assertSame(2, $payload['pagination']['page']);
        self::assertSame(2, $payload['pagination']['pages']);
        self::assertSame(26, $payload['pagination']['from']);
        self::assertSame(26, $payload['pagination']['to']);
    }

    public function testAPageBeyondTheLastOneFallsBackToTheLastPageWithData(): void
    {
        $readModel = new ListTripsReadModelFake(total: 30);
        $payload = (new ListOperationalTrips($readModel))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver'], [10]),
            new OperationalTripFilters(status: 'EN_CURSO'),
            9,
            10,
        );

        self::assertSame([[9, 10], [3, 10]], $readModel->pageRequests);
        self::assertSame(3, $payload['pagination']['page']);
        self::assertSame(21, $payload['pagination']['from']);
        self::assertSame(30, $payload['pagination']['to']);
        self::assertTrue($payload['hasActiveFilters']);
    }

    public function testWithoutTripsTheRangeIsEmpty(): void
    {
        $payload = (new ListOperationalTrips(new ListTripsReadModelFake(total: 0)))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver'], [10]),
            new OperationalTripFilters(),
        );

        self::assertSame(1, $payload['pagination']['pages']);
        self::assertSame(0, $payload['pagination']['from']);
        self::assertSame(0, $payload['pagination']['to']);
    }

    public function testRejectsAPageSizeOutsideTheCatalog(): void
    {
        $this->expectException(DomainException::class);

        (new ListOperationalTrips(new ListTripsReadModelFake()))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver'], [10]),
            new OperationalTripFilters(),
            1,
            1000,
        );
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

    /** @var list<array{int,int}> */
    public array $pageRequests = [];

    public function __construct(private readonly int $total = 25)
    {
    }

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
        $this->pageRequests[] = [$page, $perPage];
        $offset = ($page - 1) * $perPage;
        $count = max(0, min($perPage, $this->total - $offset));
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = ['id' => $offset + $i, 'numero' => $offset + $i];
        }

        return ['items' => $items, 'total' => $this->total];
    }

    public function find(int $companyId, ?array $branchIds, int $tripId): ?array
    {
        return null;
    }
    public function equipmentOptions(int $companyId, ?array $branchIds): array { return []; }
}
