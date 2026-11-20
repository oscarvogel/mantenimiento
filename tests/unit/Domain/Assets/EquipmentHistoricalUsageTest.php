<?php

declare(strict_types=1);

use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentType;
use App\Domain\Measurement\UsageMeasurement;
use PHPUnit\Framework\TestCase;

final class EquipmentHistoricalUsageTest extends TestCase
{
    public function testHistoricalReadingCoveredByALaterOneKeepsTheCurrentOdometer(): void
    {
        $equipment = $this->truck(1_729_288);

        $equipment->recordHistoricalUsage(UsageMeasurement::from(1_727_587, null), true);

        self::assertSame(1_729_288, $equipment->currentKilometers());
    }

    public function testWithoutALaterReadingItIsARegression(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Un retroceso requiere permiso');

        $this->truck(1_729_288)->recordHistoricalUsage(UsageMeasurement::from(1_727_587, null), false);
    }

    public function testOnlyAReadingBelowTheCurrentOdometerCanBeHistorical(): void
    {
        $this->expectException(DomainException::class);

        $this->truck(1_729_288)->recordHistoricalUsage(UsageMeasurement::from(1_730_000, null), true);
    }

    public function testDecommissionedEquipmentRejectsHistoricalReadings(): void
    {
        $equipment = Equipment::reconstitute(10, 5, 7, new EquipmentType(3, 'Camión', true, true), 'CAM-01', null, Equipment::INACTIVE, new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-11-01'), null, 1_729_288, null);
        $this->expectException(DomainException::class);

        $equipment->recordHistoricalUsage(UsageMeasurement::from(1_727_587, null), true);
    }

    private function truck(?int $kilometers): Equipment
    {
        return Equipment::reconstitute(10, 5, 7, new EquipmentType(3, 'Camión', true, true), 'CAM-01', null, Equipment::ACTIVE, new DateTimeImmutable('2026-08-01'), null, null, $kilometers, null);
    }
}
