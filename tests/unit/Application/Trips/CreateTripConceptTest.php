<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\CreateTripConcept;
use App\Application\Trips\CreateTripConceptCommand;
use App\Application\Trips\OperationalTripFilters;
use App\Application\Trips\Port\OperationalTripReadModel;
use App\Application\Trips\Port\TripConceptCatalog;
use App\Application\Trips\Port\TripDirectoryLookup;
use App\Domain\Trips\TripConcept;
use DomainException;
use PHPUnit\Framework\TestCase;

final class CreateTripConceptTest extends TestCase
{
    private function actor(array $permissions = ['viajes.operar'], int $companyId = 7): ActorContext
    {
        return new ActorContext(12, $companyId, false, true, [], $permissions, []);
    }

    public function testOperatorCreatesAConceptWithoutLeavingTheTrip(): void
    {
        $catalog = $this->createMock(TripConceptCatalog::class);
        $catalog->method('existsByName')->with(7, 'Viático cena')->willReturn(false);
        $catalog->expects(self::once())->method('create')->with(
            7,
            self::callback(static fn (TripConcept $concept): bool => $concept->name() === 'Viático cena'
                && $concept->category() === TripConcept::CATEGORIA_GASTO
                && $concept->unit() === TripConcept::UNIDAD_UNIDAD
                && $concept->unitAmount() === 5000.0
                && $concept->currency() === 'ARS'
                && $concept->amountEditable()),
            12,
        )->willReturn(31);

        $result = (new CreateTripConcept($catalog, $this->readModel(), $this->directory()))->execute(
            $this->actor(),
            42,
            CreateTripConceptCommand::fromInput([
                'nombre' => ' Viático cena ',
                'categoria' => 'gasto',
                'unidad' => 'unidad',
                'importe_unitario' => '5000',
                'moneda' => 'ars',
                'permite_editar_importe' => '1',
            ]),
        );

        self::assertSame([
            'id' => 31,
            'label' => 'Viático cena',
            'categoria' => TripConcept::CATEGORIA_GASTO,
            'unit' => TripConcept::UNIDAD_UNIDAD,
            'amount' => 5000.0,
            'currency' => 'ARS',
            'editable' => true,
        ], $result);
    }

    public function testMasterEditorCanAlsoCreateAConcept(): void
    {
        $catalog = $this->createMock(TripConceptCatalog::class);
        $catalog->method('existsByName')->willReturn(false);
        $catalog->method('create')->willReturn(5);

        $result = (new CreateTripConcept($catalog, $this->readModel(), $this->directory()))->execute(
            $this->actor(['viajes.maestros.editar']),
            42,
            CreateTripConceptCommand::fromInput([
                'nombre' => 'Estadía Cámara de frío',
                'categoria' => 'HABER',
                'unidad' => 'DIA',
                'importe_unitario' => '80000',
            ]),
        );

        self::assertSame(5, $result['id']);
        self::assertSame(TripConcept::UNIDAD_DIA, $result['unit']);
    }

    public function testRejectsAnActorWithoutPermission(): void
    {
        $catalog = $this->createMock(TripConceptCatalog::class);
        $catalog->expects(self::never())->method('create');

        $this->expectException(DomainException::class);
        (new CreateTripConcept($catalog, $this->readModel(), $this->directory()))->execute(
            $this->actor(['viajes.ver']),
            42,
            CreateTripConceptCommand::fromInput(['nombre' => 'Peaje', 'categoria' => 'GASTO', 'unidad' => 'UNIDAD', 'importe_unitario' => '100']),
        );
    }

    public function testRejectsATripOutsideTheActorScope(): void
    {
        $catalog = $this->createMock(TripConceptCatalog::class);
        $catalog->expects(self::never())->method('create');

        $this->expectException(DomainException::class);
        (new CreateTripConcept($catalog, $this->readModel(false), $this->directory()))->execute(
            $this->actor(),
            999,
            CreateTripConceptCommand::fromInput(['nombre' => 'Peaje', 'categoria' => 'GASTO', 'unidad' => 'UNIDAD', 'importe_unitario' => '100']),
        );
    }

    public function testRejectsADuplicatedName(): void
    {
        $catalog = $this->createMock(TripConceptCatalog::class);
        $catalog->method('existsByName')->with(7, 'Peaje')->willReturn(true);
        $catalog->expects(self::never())->method('create');

        $this->expectException(DomainException::class);
        (new CreateTripConcept($catalog, $this->readModel(), $this->directory()))->execute(
            $this->actor(),
            42,
            CreateTripConceptCommand::fromInput(['nombre' => 'Peaje', 'categoria' => 'GASTO', 'unidad' => 'UNIDAD', 'importe_unitario' => '100']),
        );
    }

    public function testRejectsAnInvalidConcept(): void
    {
        $catalog = $this->createMock(TripConceptCatalog::class);
        $catalog->expects(self::never())->method('create');

        $this->expectException(DomainException::class);
        (new CreateTripConcept($catalog, $this->readModel(), $this->directory()))->execute(
            $this->actor(),
            42,
            CreateTripConceptCommand::fromInput(['nombre' => 'Peaje', 'categoria' => 'GASTO', 'unidad' => 'UNIDAD', 'importe_unitario' => '-5']),
        );
    }

    public function testRejectsANonNumericAmount(): void
    {
        $this->expectException(DomainException::class);
        CreateTripConceptCommand::fromInput(['nombre' => 'Peaje', 'categoria' => 'GASTO', 'unidad' => 'UNIDAD', 'importe_unitario' => 'mucho']);
    }

    public function testRejectsACurrencyThatIsNotAvailable(): void
    {
        $catalog = $this->createMock(TripConceptCatalog::class);
        $catalog->method('existsByName')->willReturn(false);
        $catalog->expects(self::never())->method('create');

        $this->expectException(DomainException::class);
        (new CreateTripConcept($catalog, $this->readModel(), $this->directory(false)))->execute(
            $this->actor(),
            42,
            CreateTripConceptCommand::fromInput(['nombre' => 'Peaje', 'categoria' => 'GASTO', 'unidad' => 'UNIDAD', 'importe_unitario' => '100', 'moneda' => 'XXX']),
        );
    }

    private function directory(bool $currencyExists = true): TripDirectoryLookup
    {
        return new class ($currencyExists) implements TripDirectoryLookup {
            public function __construct(private bool $currencyExists)
            {
            }

            public function customerExists(int $companyId, int $customerId): bool
            {
                return false;
            }

            public function loadUnitExists(int $companyId, int $loadUnitId): bool
            {
                return false;
            }

            public function addressBelongsToCustomer(int $companyId, int $customerId, int $addressId): bool
            {
                return false;
            }

            public function expenseCategoryExists(int $companyId, int $categoryId): bool
            {
                return false;
            }

            public function paymentMethodExists(int $companyId, int $paymentMethodId): bool
            {
                return false;
            }

            public function concept(int $companyId, int $conceptId): ?array
            {
                return null;
            }

            public function currencyExists(string $code): bool
            {
                return $this->currencyExists;
            }

            public function exchangeRateFor(int $companyId, string $currency): ?float
            {
                return strtoupper(trim($currency)) === 'ARS' ? 1.0 : 1.0;
            }
        };
    }

    private function readModel(bool $found = true): OperationalTripReadModel
    {
        return new class ($found) implements OperationalTripReadModel {
            public function __construct(private bool $found)
            {
            }

            public function branches(int $companyId, ?array $branchIds): array
            {
                return [];
            }

            public function equipmentOptions(int $companyId, ?array $branchIds): array
            {
                return [];
            }

            public function list(int $companyId, ?array $branchIds, OperationalTripFilters $filters, int $page, int $perPage): array
            {
                return ['items' => [], 'total' => 0];
            }

            public function find(int $companyId, ?array $branchIds, int $tripId): ?array
            {
                return $this->found ? ['trip' => ['id' => $tripId]] : null;
            }
        };
    }
}
