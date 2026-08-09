<?php

declare(strict_types=1);

use App\Domain\Assets\Brand;
use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentModel;
use App\Domain\Assets\EquipmentRelation;
use App\Domain\Assets\EquipmentType;
use PHPUnit\Framework\TestCase;

final class EquipmentPhase2CTest extends TestCase
{
    public function testTechnicalProfileIsNormalizedAndRequiresBrandForModel(): void
    {
        $equipment = Equipment::create(
            1, 2, new EquipmentType(3, 'Tractor', true, true), ' t-01 ', null,
            new DateTimeImmutable('2026-08-08'), null, 10, 20, 2024, ' abc 123 ', ' mot 9 ',
        );

        self::assertSame(10, $equipment->brandId());
        self::assertSame(20, $equipment->modelId());
        self::assertSame(2024, $equipment->year());
        self::assertSame('ABC 123', $equipment->chassis());
        self::assertSame('MOT 9', $equipment->engine());

        $this->expectException(DomainException::class);
        Equipment::create(1, 2, new EquipmentType(3, 'Tractor', true, true), 'T-02', null, new DateTimeImmutable(), null, null, 20);
    }

    public function testBrandAndModelCanBeInactivatedButNotEditedAfterwards(): void
    {
        $brand = Brand::create(5, '  Mercedes   Benz ');
        self::assertSame('Mercedes Benz', $brand->name());
        $brand->inactivate();
        self::assertFalse($brand->isActive());

        $model = EquipmentModel::create(5, 7, 9, 'Actros');
        $model->assertCompatible(7, 9);
        $model->inactivate();

        $this->expectException(DomainException::class);
        $model->rename('Actros II');
    }

    public function testModelRejectsAnIncompatibleBrandOrType(): void
    {
        $model = EquipmentModel::create(5, 7, 9, 'Actros');

        $this->expectException(DomainException::class);
        $model->assertCompatible(8, 9);
    }

    public function testRelationRejectsSelfRelationAndInvalidChronology(): void
    {
        try {
            EquipmentRelation::start(5, 10, 10, EquipmentRelation::OTHER, new DateTimeImmutable('2026-08-08'), 3);
            self::fail('La autorrelación debió rechazarse.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $relation = EquipmentRelation::start(5, 10, 11, EquipmentRelation::TRACTOR_TRAILER, new DateTimeImmutable('2026-08-08 10:00:00'), 3);
        self::assertTrue($relation->isIncompatibleWithActiveRelation());

        $this->expectException(DomainException::class);
        $relation->finish(new DateTimeImmutable('2026-08-08 09:59:00'), 4);
    }

    public function testFinishingRelationKeepsItsHistory(): void
    {
        $relation = EquipmentRelation::reconstitute(
            12, 5, 10, 11, EquipmentRelation::OTHER, new DateTimeImmutable('2026-08-08'), null, 3, null, 'Acople auxiliar', null,
        );
        $relation->finish(new DateTimeImmutable('2026-08-10'), 4, 'Fin de asignación');

        self::assertSame('2026-08-10', $relation->endedAt()?->format('Y-m-d'));
        self::assertSame(4, $relation->endedBy());
        self::assertSame('Fin de asignación', $relation->endingNotes());
        self::assertSame('Acople auxiliar', $relation->notes());
    }
}
