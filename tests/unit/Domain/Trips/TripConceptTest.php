<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Trips;

use App\Domain\Trips\TripConcept;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TripConceptTest extends TestCase
{
    public function testAcceptsAWellFormedConcept(): void
    {
        $concept = new TripConcept('  Estadía Sider ', 'haber', 'dia', 50000.0, 'ars', false);

        self::assertSame('Estadía Sider', $concept->name());
        self::assertSame(TripConcept::CATEGORIA_HABER, $concept->category());
        self::assertSame(TripConcept::UNIDAD_DIA, $concept->unit());
        self::assertSame(50000.0, $concept->unitAmount());
        self::assertSame('ARS', $concept->currency());
        self::assertFalse($concept->amountEditable());
        self::assertTrue($concept->appliesToEarnings());
        self::assertFalse($concept->appliesToExpenses());
        self::assertTrue($concept->isDaily());
    }

    public function testBothCategoryAppliesToExpensesAndEarnings(): void
    {
        $concept = new TripConcept('Viático', TripConcept::CATEGORIA_AMBOS, TripConcept::UNIDAD_UNIDAD, 5000);

        self::assertTrue($concept->appliesToExpenses());
        self::assertTrue($concept->appliesToEarnings());
        self::assertFalse($concept->isDaily());
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(DomainException::class);
        new TripConcept('   ', TripConcept::CATEGORIA_GASTO, TripConcept::UNIDAD_UNIDAD, 100);
    }

    public function testRejectsNameLongerThanTheColumn(): void
    {
        $this->expectException(DomainException::class);
        new TripConcept(str_repeat('a', TripConcept::MAX_NAME_LENGTH + 1), TripConcept::CATEGORIA_GASTO, TripConcept::UNIDAD_UNIDAD, 100);
    }

    public function testRejectsUnknownCategory(): void
    {
        $this->expectException(DomainException::class);
        new TripConcept('Peaje', 'PAGO', TripConcept::UNIDAD_UNIDAD, 100);
    }

    public function testRejectsUnknownUnit(): void
    {
        $this->expectException(DomainException::class);
        new TripConcept('Peaje', TripConcept::CATEGORIA_GASTO, 'KILOMETRO', 100);
    }

    public function testRejectsNegativeUnitAmount(): void
    {
        $this->expectException(DomainException::class);
        new TripConcept('Peaje', TripConcept::CATEGORIA_GASTO, TripConcept::UNIDAD_UNIDAD, -1);
    }

    public function testRejectsInvalidCurrency(): void
    {
        $this->expectException(DomainException::class);
        new TripConcept('Peaje', TripConcept::CATEGORIA_GASTO, TripConcept::UNIDAD_UNIDAD, 100, 'PESOS');
    }

    public function testEmptyCurrencyFallsBackToPesos(): void
    {
        $concept = new TripConcept('Peaje', TripConcept::CATEGORIA_GASTO, TripConcept::UNIDAD_UNIDAD, 100, '');

        self::assertSame('ARS', $concept->currency());
    }
}
