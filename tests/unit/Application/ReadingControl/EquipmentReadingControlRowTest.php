<?php

declare(strict_types=1);

use App\Application\ReadingControl\EquipmentReadingControlRow;
use App\Application\ReadingControl\ReadingControlFilter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EquipmentReadingControlRowTest extends TestCase
{
    private function row(
        ?int $lastKm = 185000,
        ?string $lastReadingAt = '2026-09-28 08:30:00',
        ?int $days = 0,
        ?int $driverEmployeeId = 55,
        ?string $driverPhone = '3514449999',
    ): EquipmentReadingControlRow {
        return new EquipmentReadingControlRow(
            equipmentId: 10,
            equipmentCode: 'CAM-01',
            equipmentPlate: 'AA123BB',
            typeName: 'Camión',
            branchId: 7,
            branchName: 'Central',
            controlsKm: true,
            driverEmployeeId: $driverEmployeeId,
            driverName: 'Pérez, Juan',
            driverPhone: $driverPhone,
            lastKm: $lastKm,
            lastReadingAt: $lastReadingAt,
            daysSinceLastReading: $days,
            equipmentUrl: '/mantenimiento/equipos/10',
        );
    }

    private function filter(string $key): ReadingControlFilter
    {
        return ReadingControlFilter::fromKey($key, new DateTimeImmutable('2026-09-28 10:00:00'));
    }

    public function testEquipmentWithoutReadingIsExplicitlyDetected(): void
    {
        $row = $this->row(null, null, null);

        self::assertFalse($row->hasReading());
        self::assertNull($row->daysSinceLastReading);
        self::assertSame('SIN_LECTURA', $row->status($this->filter(ReadingControlFilter::ALL)));
    }

    public function testEquipmentWithReadingKeepsKilometersAndDate(): void
    {
        $row = $this->row(185000, '2026-09-28 08:30:00', 0);

        self::assertTrue($row->hasReading());
        self::assertSame(185000, $row->lastKm);
        self::assertSame('2026-09-28 08:30:00', $row->lastReadingAt);
    }

    public function testAntiquityDrivesTheVisibleStatus(): void
    {
        self::assertSame('HOY', $this->row(1, '2026-09-28 08:30:00', 0)->status($this->filter(ReadingControlFilter::ALL)));
        self::assertSame('REVISAR', $this->row(1, '2026-09-27 08:30:00', 1)->status($this->filter(ReadingControlFilter::ALL)));
        self::assertSame('ANTIGUO', $this->row(1, '2026-09-01 08:30:00', 27)->status($this->filter(ReadingControlFilter::ALL)));
    }

    public function testDriverAndPhoneAreReportedIndependently(): void
    {
        self::assertTrue($this->row()->hasDriver());
        self::assertTrue($this->row()->hasValidPhone());

        self::assertFalse($this->row(1, null, 0, null, '3514449999')->hasDriver());
        self::assertFalse($this->row(1, null, 0, 55, '   ')->hasValidPhone());
    }

    public function testInvalidReadingDateIsTreatedAsAbsent(): void
    {
        $row = $this->row(100, 'no-es-una-fecha', 3);

        self::assertNull($row->lastReadingDate());
    }
}
