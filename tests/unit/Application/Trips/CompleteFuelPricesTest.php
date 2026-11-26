<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\CompleteFuelPrices;
use App\Application\Trips\FuelPriceEntry;
use App\Application\Trips\ListPendingFuelPrices;
use App\Application\Trips\Port\PendingFuelPriceRepository;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Application\Trips\Port\TripsUnitOfWork;
use App\Domain\Trips\TripMoney;
use PHPUnit\Framework\TestCase;

final class CompleteFuelPricesTest extends TestCase
{
    public function testCompletesPricesEvenOnFinishedTrips(): void
    {
        $fuel = new PendingFuelFake([
            1 => ['viaje_numero' => 7, 'viaje_estado' => 'FINALIZADO', 'con_precio' => false],
            2 => ['viaje_numero' => 8, 'viaje_estado' => 'EN_CURSO', 'con_precio' => false],
        ]);

        $saved = $this->handler($fuel)->execute($this->actor(), [
            new FuelPriceEntry(1, 145_000.0),
            new FuelPriceEntry(2, 100.0, 'USD', 1500.0),
        ]);

        self::assertSame(2, $saved);
        self::assertSame(145_000.0, $fuel->completed[1]->amountArs());
        self::assertSame(150_000.0, $fuel->completed[2]->amountArs());
        self::assertSame([5, [10]], $fuel->scope);
    }

    public function testRejectsCancelledTrips(): void
    {
        $fuel = new PendingFuelFake([1 => ['viaje_numero' => 7, 'viaje_estado' => 'CANCELADO', 'con_precio' => false]]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('#7 está cancelado');
        $this->handler($fuel)->execute($this->actor(), [new FuelPriceEntry(1, 1000.0)]);
    }

    public function testDoesNotOverwriteALoadThatAlreadyHasAPrice(): void
    {
        $fuel = new PendingFuelFake([1 => ['viaje_numero' => 7, 'viaje_estado' => 'EN_CURSO', 'con_precio' => true]]);

        try {
            $this->handler($fuel)->execute($this->actor(), [new FuelPriceEntry(1, 1000.0)]);
            self::fail('Tenía que rechazar la carga con precio.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('ya tiene precio', $exception->getMessage());
        }
        self::assertSame([], $fuel->completed);
    }

    public function testRejectsLoadsOutsideTheScope(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ya no existe o no está autorizada');
        $this->handler(new PendingFuelFake([]))->execute($this->actor(), [new FuelPriceEntry(99, 1000.0)]);
    }

    public function testRejectsDuplicatesEmptyBatchesAndZeroAmounts(): void
    {
        $fuel = new PendingFuelFake([1 => ['viaje_numero' => 7, 'viaje_estado' => 'EN_CURSO', 'con_precio' => false]]);
        foreach ([
            [[new FuelPriceEntry(1, 10.0), new FuelPriceEntry(1, 20.0)], 'dos veces'],
            [[], 'al menos una'],
            [[new FuelPriceEntry(1, 0.0)], 'mayor que cero'],
        ] as [$entries, $message]) {
            try {
                $this->handler($fuel)->execute($this->actor(), $entries);
                self::fail('Tenía que rechazar: ' . $message);
            } catch (DomainException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
        self::assertSame([], $fuel->completed);
    }

    public function testRequiresOperatePermission(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No tenés permiso');
        $this->handler(new PendingFuelFake([]))->execute(
            new ActorContext(1, 5, false, false, [], ['viajes.ver'], [10]),
            [new FuelPriceEntry(1, 10.0)],
        );
    }

    public function testListSummarizesPendingLoads(): void
    {
        $fuel = new PendingFuelFake([]);
        $fuel->pendingRows = [
            ['id' => 1, 'viaje_id' => 3, 'viaje_numero' => 7, 'viaje_estado' => 'FINALIZADO', 'sucursal' => 'Central', 'fecha' => '2026-11-14 08:00:00', 'litros' => 300.0, 'kilometraje' => null, 'estacion' => null, 'comprobante' => null, 'observaciones' => null, 'tractor' => 'AA111AA'],
            ['id' => 2, 'viaje_id' => 3, 'viaje_numero' => 7, 'viaje_estado' => 'FINALIZADO', 'sucursal' => 'Central', 'fecha' => '2026-11-15 08:00:00', 'litros' => 120.5, 'kilometraje' => null, 'estacion' => null, 'comprobante' => null, 'observaciones' => null, 'tractor' => 'AA111AA'],
        ];

        $payload = (new ListPendingFuelPrices($fuel))->execute($this->actor());

        self::assertSame(['loads' => 2, 'trips' => 1, 'liters' => 420.5], $payload['summary']);
        self::assertStringEndsWith('viajes/operativos/3', $payload['loads'][0]['url']);
        self::assertSame('ARS', $payload['currencies'][0]['code']);
    }

    private function handler(PendingFuelFake $fuel): CompleteFuelPrices
    {
        return new CompleteFuelPrices($fuel, new FuelPriceDirectoryFake(), new FuelPriceUnitOfWorkFake());
    }

    private function actor(): ActorContext
    {
        return new ActorContext(1, 5, false, false, [], ['viajes.operar'], [10]);
    }
}

final class PendingFuelFake implements PendingFuelPriceRepository
{
    /** @var array<int,TripMoney> */
    public array $completed = [];
    public array $scope = [];
    public array $pendingRows = [];

    /** @param array<int,array{viaje_numero:int,viaje_estado:string,con_precio:bool}> $loads */
    public function __construct(private array $loads)
    {
    }

    public function pending(int $companyId, ?array $branchIds): array
    {
        return $this->pendingRows;
    }

    public function findForUpdate(int $companyId, ?array $branchIds, int $fuelId): ?array
    {
        $this->scope = [$companyId, $branchIds];
        if (! isset($this->loads[$fuelId])) {
            return null;
        }

        return ['id' => $fuelId, 'viaje_id' => 3] + $this->loads[$fuelId];
    }

    public function completePrice(int $companyId, int $fuelId, TripMoney $money, int $actorUserId): bool
    {
        $this->completed[$fuelId] = $money;

        return true;
    }

    public function currencies(int $companyId): array
    {
        return [['code' => 'ARS', 'label' => 'Peso', 'rate' => 1.0]];
    }
}

final class FuelPriceDirectoryFake implements TripDirectoryLookup
{
    public function customerExists(int $companyId, int $customerId): bool { return true; }
    public function loadUnitExists(int $companyId, int $loadUnitId): bool { return true; }
    public function addressBelongsToCustomer(int $companyId, int $customerId, int $addressId): bool { return true; }
    public function expenseCategoryExists(int $companyId, int $categoryId): bool { return true; }
    public function paymentMethodExists(int $companyId, int $paymentMethodId): bool { return true; }
    public function concept(int $companyId, int $conceptId): ?array { return null; }
    public function currencyExists(string $code): bool { return in_array($code, ['ARS', 'USD'], true); }
    public function exchangeRateFor(int $companyId, string $currency): ?float { $c = strtoupper(trim($currency)); return $c === 'ARS' ? 1.0 : 1.0; }
}

final class FuelPriceUnitOfWorkFake implements TripsUnitOfWork
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
