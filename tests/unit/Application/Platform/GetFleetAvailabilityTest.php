<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Platform;

use App\Application\Identity\ActorContext;
use App\Application\Platform\GetFleetAvailability;
use App\Application\Platform\Port\FleetAvailabilityReadModel;
use PHPUnit\Framework\TestCase;

final class GetFleetAvailabilityTest extends TestCase
{
    public function testCountsEachEquipmentOnceAndPrefersTheStoppingOrder(): void
    {
        $port = new FleetAvailabilityReadModelFake([
            self::row(1, 'TR-12', 'AB123CD', orderId: 10, orderNumber: 'OT-10', openedAt: '2026-11-01 08:00:00'),
            self::row(1, 'TR-12', 'AB123CD', orderId: 11, orderNumber: 'OT-11', openedAt: '2026-11-10 08:00:00', outOfService: true, stopStartedAt: '2026-11-20 09:00:00'),
            self::row(2, 'AC-07', null, orderId: 12, orderNumber: 'OT-12', openedAt: '2026-11-15 08:00:00', reason: 'Frenos'),
            self::row(3, 'SR-02', null),
        ]);

        $result = (new GetFleetAvailability($port))->execute(self::actor(), null);

        self::assertSame(3, $result['total']);
        self::assertSame(['operative' => 1, 'inShop' => 1, 'stopped' => 1], $result['counts']);
        self::assertCount(1, $result['stopped']);
        self::assertSame('TR-12 · AB123CD', $result['stopped'][0]['label']);
        self::assertSame('OT-11', $result['stopped'][0]['orderNumber']);
        self::assertSame('2026-11-20 09:00:00', $result['stopped'][0]['since']);
        self::assertSame('AC-07', $result['inShop'][0]['label']);
        self::assertSame('Frenos', $result['inShop'][0]['reason']);
        self::assertSame('2026-11-15 08:00:00', $result['inShop'][0]['since']);
    }

    public function testStoppedSinceFallsBackToOpenedAtWithoutStopStart(): void
    {
        $port = new FleetAvailabilityReadModelFake([
            self::row(1, 'TR-12', null, orderId: 10, orderNumber: 'OT-10', openedAt: '2026-11-01 08:00:00', outOfService: true),
        ]);

        $result = (new GetFleetAvailability($port))->execute(self::actor(), null);

        self::assertSame('2026-11-01 08:00:00', $result['stopped'][0]['since']);
    }

    public function testUnauthorizedBranchFilterIsIgnored(): void
    {
        $port = new FleetAvailabilityReadModelFake([]);

        $result = (new GetFleetAvailability($port))->execute(self::actor(), 11);

        self::assertSame([5, [10], null], $port->lastRowsCall);
        self::assertNull($result['branchId']);
    }

    // Regresión: con todas las sucursales, canAccessBranch acepta cualquier id; una sucursal
    // inexistente o de otra empresa quedaba "seleccionada" y la flota se veía vacía.
    public function testBranchOutsideTheActiveCompanyBranchesIsIgnoredForAllBranchActors(): void
    {
        $port = new FleetAvailabilityReadModelFake([]);
        $allBranches = new ActorContext(7, 5, false, true, ['Administrador'], ['equipos.ver'], []);

        $result = (new GetFleetAvailability($port))->execute($allBranches, 99);

        self::assertSame([5, null, null], $port->lastRowsCall);
        self::assertNull($result['branchId']);
    }

    public function testAuthorizedBranchFilterReachesThePort(): void
    {
        $port = new FleetAvailabilityReadModelFake([]);

        $result = (new GetFleetAvailability($port))->execute(self::actor(), 10);

        self::assertSame([5, [10], 10], $port->lastRowsCall);
        self::assertSame(10, $result['branchId']);
    }

    public function testReturnsNullForSuperAdminOrWithoutPermission(): void
    {
        $useCase = new GetFleetAvailability(new FleetAvailabilityReadModelFake([]));

        self::assertNull($useCase->execute(new ActorContext(1, null, true, true, ['Superadministrador'], ['equipos.ver'], []), null));
        self::assertNull($useCase->execute(new ActorContext(2, 5, false, false, ['Consulta'], ['ordenes.ver'], [10]), null));
    }

    public function testEmptyFleetReportsZeroTotal(): void
    {
        $result = (new GetFleetAvailability(new FleetAvailabilityReadModelFake([])))->execute(self::actor(), null);

        self::assertSame(0, $result['total']);
        self::assertSame(['operative' => 0, 'inShop' => 0, 'stopped' => 0], $result['counts']);
        self::assertSame([], $result['stopped']);
        self::assertSame([], $result['inShop']);
    }

    public function testListsAreCappedAtFiveOldestFirst(): void
    {
        $rows = [];
        foreach (range(1, 7) as $id) {
            $rows[] = self::row($id, 'EQ-' . $id, null, orderId: 100 + $id, orderNumber: 'OT-' . $id, openedAt: sprintf('2026-11-%02d 08:00:00', 10 - $id));
        }

        $result = (new GetFleetAvailability(new FleetAvailabilityReadModelFake($rows)))->execute(self::actor(), null);

        self::assertSame(7, $result['counts']['inShop']);
        self::assertCount(5, $result['inShop']);
        self::assertSame(['EQ-7', 'EQ-6', 'EQ-5', 'EQ-4', 'EQ-3'], array_column($result['inShop'], 'label'));
    }

    private static function actor(): ActorContext
    {
        return new ActorContext(7, 5, false, false, ['Responsable'], ['equipos.ver'], [10]);
    }

    /** @return array<string,mixed> */
    private static function row(
        int $equipmentId,
        string $code,
        ?string $plate,
        ?int $orderId = null,
        ?string $orderNumber = null,
        ?string $openedAt = null,
        bool $outOfService = false,
        ?string $stopStartedAt = null,
        ?string $stopEndedAt = null,
        ?string $reason = null,
    ): array {
        return [
            'equipmentId' => $equipmentId,
            'code' => $code,
            'plate' => $plate,
            'branchName' => 'Casa Central',
            'orderId' => $orderId,
            'orderNumber' => $orderNumber,
            'orderStatus' => $orderId === null ? null : 'EN_PROCESO',
            'openedAt' => $openedAt,
            'outOfService' => $outOfService,
            'stopStartedAt' => $stopStartedAt,
            'stopEndedAt' => $stopEndedAt,
            'reason' => $reason,
        ];
    }
}

final class FleetAvailabilityReadModelFake implements FleetAvailabilityReadModel
{
    /** @var array{0:int,1:?list<int>,2:?int}|null */
    public ?array $lastRowsCall = null;

    /** @param list<array<string,mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function rows(int $companyId, ?array $branchIds, ?int $branchId): array
    {
        $this->lastRowsCall = [$companyId, $branchIds, $branchId];

        return $this->rows;
    }

    public function branches(int $companyId, ?array $branchIds): array
    {
        return [['id' => 10, 'name' => 'Casa Central']];
    }
}
