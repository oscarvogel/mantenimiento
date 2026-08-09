<?php

declare(strict_types=1);

use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentType;
use App\Domain\Measurement\UsageMeasurement;
use PHPUnit\Framework\TestCase;

final class EquipmentTest extends TestCase
{
    public function testCreationNormalizesBusinessCodesAndStartsWithoutUsage(): void
    {
        $equipment = Equipment::create(1, 2, $this->bothMetrics(), '  eq-01 ', ' ab 123 cd ', new DateTimeImmutable('2026-08-08'));

        self::assertSame('EQ-01', $equipment->code());
        self::assertSame('AB 123 CD', $equipment->plate());
        self::assertSame(Equipment::ACTIVE, $equipment->status());
        self::assertNull($equipment->currentKilometers());
        self::assertNull($equipment->currentHours());
    }

    public function testPartialReadingPreservesTheOtherCurrentMetric(): void
    {
        $equipment = $this->persistedEquipment(1000, '50.0');

        $correction = $equipment->recordUsage(UsageMeasurement::from(1200, null), false, null);

        self::assertFalse($correction);
        self::assertSame(1200, $equipment->currentKilometers());
        self::assertSame('50.0', $equipment->currentHours());
    }

    public function testRegressionRequiresPermissionAndReason(): void
    {
        $equipment = $this->persistedEquipment(1000, '50.0');

        try {
            $equipment->recordUsage(UsageMeasurement::from(900, null), false, null);
            self::fail('El retroceso sin permiso debió rechazarse.');
        } catch (DomainException) {
            self::assertSame(1000, $equipment->currentKilometers());
        }

        self::assertTrue($equipment->recordUsage(
            UsageMeasurement::from(900, null),
            true,
            'Corrección por carga anterior errónea',
        ));
        self::assertSame(900, $equipment->currentKilometers());
    }

    public function testTypeDeterminesWhichMetricsCanBeRecorded(): void
    {
        $equipment = Equipment::create(
            1,
            2,
            new EquipmentType(2, 'Acoplado', true, false),
            'AC-1',
            null,
            new DateTimeImmutable('2026-08-08'),
        );

        $this->expectException(DomainException::class);
        $equipment->recordUsage(UsageMeasurement::from(null, '1.0'), false, null);
    }

    public function testDecommissionedEquipmentKeepsStateButRejectsNewReadings(): void
    {
        $equipment = $this->persistedEquipment(1000, null);
        $equipment->decommission(new DateTimeImmutable('2026-08-09'));

        self::assertSame(Equipment::INACTIVE, $equipment->status());
        self::assertSame('2026-08-09', $equipment->decommissionedAt()?->format('Y-m-d'));

        $this->expectException(DomainException::class);
        $equipment->recordUsage(UsageMeasurement::from(1100, null), false, null);
    }

    public function testProfileUpdateNormalizesFieldsWithoutChangingTypeOrBranch(): void
    {
        $equipment = $this->persistedEquipment(1000, null);

        $equipment->updateProfile('  eq-02 ', ' aa 123 bb ', '  Unidad principal  ');

        self::assertSame('EQ-02', $equipment->code());
        self::assertSame('AA 123 BB', $equipment->plate());
        self::assertSame('Unidad principal', $equipment->notes());
        self::assertSame(2, $equipment->branchId());
        self::assertSame(1, $equipment->type()->id());
    }

    public function testTransferChangesCurrentBranchAndReturnsOrigin(): void
    {
        $equipment = $this->persistedEquipment(1000, null);

        $origin = $equipment->transferTo(8, new DateTimeImmutable('2026-08-10 10:00:00'));

        self::assertSame(2, $origin);
        self::assertSame(8, $equipment->branchId());
    }

    public function testDecommissionIsExplicitlyRejectedWhenRepeated(): void
    {
        $equipment = $this->persistedEquipment(1000, null);
        $equipment->decommission(new DateTimeImmutable('2026-08-09'));

        $this->expectException(DomainException::class);
        $equipment->decommission(new DateTimeImmutable('2026-08-10'));
    }

    private function persistedEquipment(?int $kilometers, int|float|string|null $hours): Equipment
    {
        return Equipment::reconstitute(
            10,
            1,
            2,
            $this->bothMetrics(),
            'EQ-01',
            null,
            Equipment::ACTIVE,
            new DateTimeImmutable('2026-08-08'),
            null,
            null,
            $kilometers,
            $hours,
        );
    }

    private function bothMetrics(): EquipmentType
    {
        return new EquipmentType(1, 'Camión', true, true);
    }
}
