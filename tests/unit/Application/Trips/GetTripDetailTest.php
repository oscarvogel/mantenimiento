<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\GetTripDetail;
use App\Application\Trips\Port\TripDetailReadModel;
use PHPUnit\Framework\TestCase;

final class GetTripDetailTest extends TestCase
{
    public function testLoadsTripAndItsHistoryOnlyWithinActorCompanyAndBranches(): void
    {
        $readModel = new class implements TripDetailReadModel {
            public array $query = [];

            public function find(int $companyId, ?array $branchIds, int $tripId): ?array
            {
                $this->query = [$companyId, $branchIds, $tripId];

                return ['trip' => ['id' => $tripId], 'segments' => [], 'fuel' => [], 'expenses' => []];
            }
        };
        $actor = new ActorContext(12, 7, false, false, [], ['viajes.ver'], [3, 5]);

        $detail = (new GetTripDetail($readModel))->execute($actor, 42);

        self::assertSame([7, [3, 5], 42], $readModel->query);
        self::assertSame(['id' => 42], $detail['trip']);
    }

    public function testRejectsTripOutsideActorScopeAsNotFound(): void
    {
        $readModel = new class implements TripDetailReadModel {
            public function find(int $companyId, ?array $branchIds, int $tripId): ?array
            {
                return null;
            }
        };
        $actor = new ActorContext(12, 7, false, false, [], ['viajes.ver'], [3]);

        $this->expectException(DomainException::class);
        (new GetTripDetail($readModel))->execute($actor, 42);
    }

    public function testRejectsActorWithoutTripPermission(): void
    {
        $readModel = new class implements TripDetailReadModel {
            public function find(int $companyId, ?array $branchIds, int $tripId): ?array
            {
                self::fail('No debe consultar viajes sin permiso.');
            }
        };
        $actor = new ActorContext(12, 7, false, false, [], [], [3]);

        $this->expectException(DomainException::class);
        (new GetTripDetail($readModel))->execute($actor, 42);
    }
}
