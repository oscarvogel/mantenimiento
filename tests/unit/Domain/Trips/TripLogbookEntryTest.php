<?php

declare(strict_types=1);

use App\Domain\Trips\TripLogbookEntry;
use PHPUnit\Framework\TestCase;

final class TripLogbookEntryTest extends TestCase
{
    public function testCalculatesDaysByDateDifferenceAndSubtractsFreeTime(): void
    {
        $entry = $this->entry('2026-10-17', '2026-10-23');

        self::assertSame(6, $entry->presenceDays());
        self::assertSame(4.0, $entry->calculatedDays());
        self::assertSame(4.0, $entry->liquidatedDays());
        self::assertFalse($entry->hasAdjustment());
    }

    public function testSameArrivalAndDepartureDateDoesNotGenerateDays(): void
    {
        $entry = $this->entry('2026-10-16', '2026-10-16');

        self::assertSame(0, $entry->presenceDays());
        self::assertSame(0.0, $entry->calculatedDays());
    }

    public function testEachStageHasItsOwnFreeTime(): void
    {
        $entry = $this->entry('2026-10-17', '2026-10-23', 24);

        self::assertSame(5.0, $entry->calculatedDays());
    }

    public function testEntryWithoutDepartureDoesNotCalculateDays(): void
    {
        $entry = $this->entry('2026-10-17', null);

        self::assertNull($entry->presenceDays());
        self::assertNull($entry->calculatedDays());
        self::assertNull($entry->liquidatedDays());
    }

    public function testManualCorrectionRequiresReason(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('motivo');

        $this->entry('2026-10-17', '2026-10-23', 48, 3.0);
    }

    public function testManualCorrectionOverridesCalculatedDays(): void
    {
        $entry = $this->entry('2026-10-17', '2026-10-23', 48, 3.0, 'Acuerdo con el cliente');

        self::assertSame(4.0, $entry->calculatedDays());
        self::assertSame(3.0, $entry->liquidatedDays());
        self::assertTrue($entry->hasAdjustment());
        self::assertSame('Acuerdo con el cliente', $entry->adjustmentReason());
    }

    public function testRejectsDepartureBeforeArrival(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('anterior');

        $this->entry('2026-10-23', '2026-10-17');
    }

    public function testRejectsUnknownStage(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tipo');

        new TripLogbookEntry(
            1,
            'OTRA',
            'Frontera',
            new DateTimeImmutable('2026-10-17'),
            new DateTimeImmutable('2026-10-23'),
            48,
            11,
            9,
        );
    }

    private function entry(
        string $arrival,
        ?string $departure,
        int $freeHours = 48,
        ?float $adjustedDays = null,
        ?string $reason = null,
    ): TripLogbookEntry {
        return new TripLogbookEntry(
            1,
            TripLogbookEntry::FRONTERA,
            'Frontera Brasil',
            new DateTimeImmutable($arrival),
            $departure === null ? null : new DateTimeImmutable($departure),
            $freeHours,
            11,
            9,
            $adjustedDays,
            $reason,
        );
    }
}
