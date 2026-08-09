<?php

declare(strict_types=1);

use App\Domain\Measurement\EquipmentReading;
use App\Domain\Measurement\UsageMeasurement;
use PHPUnit\Framework\TestCase;

final class EquipmentReadingTest extends TestCase
{
    public function testCorrectionMustPreserveItsReason(): void
    {
        $this->expectException(DomainException::class);
        EquipmentReading::record(
            1,
            2,
            3,
            new DateTimeImmutable('2026-08-08 10:00:00'),
            UsageMeasurement::from(100, null),
            EquipmentReading::MANUAL,
            null,
            4,
            true,
            null,
            null,
        );
    }

    public function testNormalReadingDoesNotMisclassifyAnUnusedReasonAsCorrection(): void
    {
        $reading = EquipmentReading::record(
            1,
            2,
            3,
            new DateTimeImmutable('2026-08-08 10:00:00'),
            UsageMeasurement::from(null, '25.5'),
            EquipmentReading::WORK_ORDER,
            'OT-2026-000001',
            4,
            false,
            'Texto que no corresponde conservar',
            'Lectura de salida',
        );

        self::assertNull($reading->correctionReason());
        self::assertSame('25.5', $reading->measurement()->hours());
        self::assertSame('OT-2026-000001', $reading->originReference());
    }

    public function testRejectsUnknownOrigin(): void
    {
        $this->expectException(DomainException::class);
        EquipmentReading::record(
            1,
            2,
            3,
            new DateTimeImmutable(),
            UsageMeasurement::from(100, null),
            'API_EXTERNA',
            null,
            4,
            false,
            null,
            null,
        );
    }

    public function testCorrectionAnnulsOriginalAndLinksReplacementWithoutChangingHistoricalDate(): void
    {
        $original = EquipmentReading::reconstitute(
            81,
            5,
            7,
            10,
            new DateTimeImmutable('2026-07-10 08:30:00'),
            UsageMeasurement::from(1000, '20.0'),
            EquipmentReading::MANUAL,
            null,
            4,
            null,
            'Carga original',
            null,
            false,
            null,
            null,
            null,
        );

        $correctedAt = new DateTimeImmutable('2026-08-08 12:00:00');
        $replacement = $original->correct(
            UsageMeasurement::from(950, '19.5'),
            9,
            'Error de transcripción',
            'Valor comprobado',
            $correctedAt,
        );

        self::assertTrue($original->isAnnulled());
        self::assertSame($correctedAt, $original->annulledAt());
        self::assertSame(9, $original->annulledBy());
        self::assertSame('Error de transcripción', $original->annulmentReason());
        self::assertSame(81, $replacement->correctedReadingId());
        self::assertSame($original->recordedAt(), $replacement->recordedAt());
        self::assertSame(7, $replacement->branchId());
        self::assertFalse($replacement->isAnnulled());
    }

    public function testAnAlreadyAnnulledReadingCannotBeCorrectedAgain(): void
    {
        $reading = EquipmentReading::reconstitute(
            81,
            5,
            7,
            10,
            new DateTimeImmutable('2026-07-10'),
            UsageMeasurement::from(1000, null),
            EquipmentReading::MANUAL,
            null,
            4,
            null,
            null,
            null,
            true,
            new DateTimeImmutable('2026-08-01'),
            9,
            'Primera corrección',
        );

        $this->expectException(DomainException::class);
        $reading->correct(
            UsageMeasurement::from(900, null),
            9,
            'Segunda corrección',
            null,
            new DateTimeImmutable('2026-08-08'),
        );
    }
}
