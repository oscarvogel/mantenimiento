<?php

declare(strict_types=1);

use App\Application\Trips\FuelPanelFilters;
use PHPUnit\Framework\TestCase;

/**
 * Filtros del módulo de Combustible: fechas y camión.
 *
 * Una fecha mal escrita se rechaza con un mensaje que la persona pueda
 * corregir: no se cae a «últimos 30 días» en silencio.
 */
final class FuelPanelFiltersTest extends TestCase
{
    public function testAnEmptyQueryHasNoFilters(): void
    {
        $filtros = FuelPanelFilters::fromQuery([]);

        self::assertNull($filtros->from);
        self::assertNull($filtros->to);
        self::assertNull($filtros->equipmentId);
        self::assertNull($filtros->branchId);
    }

    public function testReadsTheDatesTheTruckAndTheBranch(): void
    {
        $filtros = FuelPanelFilters::fromQuery([
            'desde' => '2026-11-01',
            'hasta' => '2026-11-24',
            'camion' => '7',
            'sucursal' => '12',
        ]);

        self::assertSame('2026-11-01', $filtros->from);
        self::assertSame('2026-11-24', $filtros->to);
        self::assertSame(7, $filtros->equipmentId);
        self::assertSame(12, $filtros->branchId);
    }

    public function testRejectsADateThatIsNotADate(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La fecha desde no es una fecha valida (AAAA-MM-DD).');

        FuelPanelFilters::fromQuery(['desde' => 'ayer']);
    }

    public function testRejectsAPeriodThatGoesBackwards(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La fecha desde no puede ser posterior a la fecha hasta.');

        FuelPanelFilters::fromQuery(['desde' => '2026-11-24', 'hasta' => '2026-11-01']);
    }

    public function testRejectsAPeriodLongerThanAYear(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El período no puede superar un año.');

        FuelPanelFilters::fromQuery(['desde' => '2025-01-01', 'hasta' => '2026-11-01']);
    }

    public function testIgnoresATruckThatIsNotANumber(): void
    {
        $filtros = FuelPanelFilters::fromQuery(['camion' => 'todos']);

        self::assertNull($filtros->equipmentId);
    }
}
