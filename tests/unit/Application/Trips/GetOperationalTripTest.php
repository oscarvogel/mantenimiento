<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\GetOperationalTrip;
use App\Application\Trips\OperationalTripFilters;
use App\Application\Trips\Port\OperationalTripReadModel;
use PHPUnit\Framework\TestCase;

final class GetOperationalTripTest extends TestCase
{
    public function testLoadsDetailWithinActorScopeAndEnablesActionsForPlannedTrip(): void
    {
        $readModel = new GetOperationalTripReadModelFake('PLANIFICADO');
        $detail = (new GetOperationalTrip($readModel))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver', 'viajes.operar'], [10]),
            55,
        );

        self::assertSame([5, [10], 55], $readModel->query);
        self::assertSame(42, $detail['trip']['numero']);
        self::assertTrue($detail['canStart']);
        self::assertTrue($detail['canCancel']);
        self::assertSame(base_url('viajes/operativos/55'), $detail['links']['detail']);
    }

    public function testHidesActionsWhenTripAlreadyStarted(): void
    {
        $readModel = new GetOperationalTripReadModelFake('EN_CURSO');
        $detail = (new GetOperationalTrip($readModel))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver', 'viajes.operar'], [10]),
            55,
        );

        self::assertFalse($detail['canStart']);
        self::assertFalse($detail['canCancel']);
    }

    public function testRejectsActorWithoutReadPermission(): void
    {
        $this->expectException(DomainException::class);

        (new GetOperationalTrip(new GetOperationalTripReadModelFake('PLANIFICADO')))->execute(
            new ActorContext(1, 5, false, false, [], [], [10]),
            55,
        );
    }

    public function testTreatsTripOutsideScopeAsNotFound(): void
    {
        $readModel = new GetOperationalTripReadModelFake(null);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no existe');

        (new GetOperationalTrip($readModel))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver'], [10]),
            55,
        );
    }
}

final class GetOperationalTripReadModelFake implements OperationalTripReadModel
{
    public array $query = [];

    public function __construct(private ?string $status)
    {
    }

    public function branches(int $companyId, ?array $branchIds): array
    {
        return [];
    }

    public function list(int $companyId, ?array $branchIds, OperationalTripFilters $filters, int $page, int $perPage): array
    {
        return ['items' => [], 'total' => 0];
    }

    public function find(int $companyId, ?array $branchIds, int $tripId): ?array
    {
        $this->query = [$companyId, $branchIds, $tripId];
        if ($this->status === null) {
            return null;
        }

        return [
            'trip' => ['id' => $tripId, 'numero' => 42, 'estado' => $this->status],
            'assignments' => [],
            'stops' => [],
        ];
    }
}
