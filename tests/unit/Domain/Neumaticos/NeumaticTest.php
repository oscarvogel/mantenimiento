<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Neumaticos;

use App\Domain\Neumaticos\Neumatic;
use App\Domain\Neumaticos\NeumaticMounting;
use App\Domain\Neumaticos\NeumaticNumber;
use App\Domain\Neumaticos\NeumaticPerformance;
use App\Domain\Neumaticos\NeumaticPosition;
use App\Domain\Neumaticos\NeumaticStatus;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class NeumaticTest extends TestCase
{
    public function testRegistersANewTireWithItsFirstLogEntry(): void
    {
        $tire = $this->newTire();

        self::assertNull($tire->id());
        self::assertSame('TSA 181917', $tire->number()->value);
        self::assertSame(NeumaticStatus::NEW, $tire->status());
        self::assertFalse($tire->isMounted());
        self::assertCount(1, $tire->statusLog());
        self::assertNull($tire->statusLog()[0]->from);
        self::assertSame(NeumaticStatus::NEW, $tire->statusLog()[0]->to);
    }

    public function testNormalizesTheTireNumber(): void
    {
        self::assertTrue((new NeumaticNumber('  tsa   001 '))->equals(new NeumaticNumber('TSA 001')));
    }

    public function testRejectsAnEmptyOrTooLongNumber(): void
    {
        $this->expectException(DomainException::class);

        new NeumaticNumber('   ');
    }

    public function testRejectsANumberLongerThanTheLegacyColumn(): void
    {
        $this->expectException(DomainException::class);

        new NeumaticNumber(str_repeat('9', 21));
    }

    public function testRequiresCompanyAndBranch(): void
    {
        $this->expectException(DomainException::class);

        Neumatic::register(0, 1, new NeumaticNumber('TSA 1'), $this->at('2026-11-01'));
    }

    public function testCannotBeRegisteredInAFinalState(): void
    {
        $this->expectException(DomainException::class);

        Neumatic::register(1, 1, new NeumaticNumber('TSA 1'), $this->at('2026-11-01'), initialStatus: NeumaticStatus::SOLD);
    }

    public function testRejectsAManufactureYearAfterTheRegistration(): void
    {
        $this->expectException(DomainException::class);

        Neumatic::register(1, 1, new NeumaticNumber('TSA 1'), $this->at('2026-11-01'), manufactureYear: 2027);
    }

    public function testDescriptionChangesDoNotTouchTheNumber(): void
    {
        $tire = $this->newTire();

        $tire->describe(3, 5, 2, '295/80 R22.5', 2025, $this->at('2026-11-02'));

        self::assertSame('TSA 181917', $tire->number()->value);
        self::assertSame(3, $tire->brandId());
        self::assertSame('295/80 R22.5', $tire->size());
        self::assertSame(2025, $tire->manufactureYear());
    }

    public function testChangesStatusAndKeepsTheLog(): void
    {
        $tire = $this->newTire();

        $tire->changeStatus(NeumaticStatus::HALF_USED, $this->at('2026-11-10'), 'Control de banda');

        self::assertSame(NeumaticStatus::HALF_USED, $tire->status());
        self::assertCount(2, $tire->statusLog());
        self::assertSame(NeumaticStatus::NEW, $tire->statusLog()[1]->from);
        self::assertSame('Control de banda', $tire->statusLog()[1]->reason);
    }

    public function testRejectsAnInvalidTransitionWithAHumanMessage(): void
    {
        $tire = $this->newTire();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Un neumático en estado "Nueva" no puede pasar a "Baja definitiva".');

        $tire->changeStatus(NeumaticStatus::WRITTEN_OFF, $this->at('2026-11-10'), 'Rotura');
    }

    public function testAStatusChangeNeedsAReason(): void
    {
        $this->expectException(DomainException::class);

        $this->newTire()->changeStatus(NeumaticStatus::HALF_USED, $this->at('2026-11-10'), '  ');
    }

    public function testAStatusChangeCannotGoBackInTime(): void
    {
        $tire = $this->newTire();
        $tire->changeStatus(NeumaticStatus::HALF_USED, $this->at('2026-11-10'), 'Control');

        $this->expectException(DomainException::class);

        $tire->changeStatus(NeumaticStatus::IN_REPAIR, $this->at('2026-11-09'), 'Pinchadura');
    }

    public function testAMountedTireMustBeRemovedBeforeGoingToRepair(): void
    {
        $tire = $this->newTire();
        $tire->changeStatus(NeumaticStatus::HALF_USED, $this->at('2026-11-02'), 'Control');
        $tire->mount(10, $this->position(), $this->at('2026-11-03'), 100_000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Extraé el neumático del equipo antes de pasarlo a "En reparación".');

        $tire->changeStatus(NeumaticStatus::IN_REPAIR, $this->at('2026-11-04'), 'Pinchadura');
    }

    public function testMountsAndRemovesReturningThePerformance(): void
    {
        $tire = $this->newTire();

        $tire->mount(10, $this->position(), $this->at('2026-11-03'), 100_000);
        self::assertTrue($tire->isMounted());
        self::assertSame(10, $tire->currentMounting()?->equipmentId);

        $performance = $tire->remove($this->at('2026-11-20'), 112_500);

        self::assertFalse($tire->isMounted());
        self::assertTrue($performance->reliable);
        self::assertSame(12_500, $performance->kilometers);
        self::assertSame('12.500 km', $performance->describe());
        self::assertCount(1, $tire->mountings());
    }

    public function testATireCannotBeMountedTwice(): void
    {
        $tire = $this->newTire();
        $tire->mount(10, $this->position(), $this->at('2026-11-03'), 100_000);

        $this->expectException(DomainException::class);

        $tire->mount(11, $this->position(), $this->at('2026-11-04'), 50_000);
    }

    public function testATireOutOfServiceCannotBeMounted(): void
    {
        $tire = $this->newTire();
        $tire->changeStatus(NeumaticStatus::FOR_SALE, $this->at('2026-11-02'), 'Sobrante');

        $this->expectException(DomainException::class);

        $tire->mount(10, $this->position(), $this->at('2026-11-03'), 100_000);
    }

    public function testRemovalCannotBeBeforeMounting(): void
    {
        $tire = $this->newTire();
        $tire->mount(10, $this->position(), $this->at('2026-11-03'), 100_000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La fecha de extracción no puede ser anterior a la de colocación.');

        $tire->remove($this->at('2026-11-02'), 110_000);
    }

    public function testRemovingAnUnmountedTireIsRejected(): void
    {
        $this->expectException(DomainException::class);

        $this->newTire()->remove($this->at('2026-11-02'), 110_000);
    }

    public function testLowerRemovalKilometersLeaveThePerformanceUnreliable(): void
    {
        $tire = $this->newTire();
        $tire->mount(10, $this->position(), $this->at('2026-11-03'), 100_000);

        $performance = $tire->remove($this->at('2026-11-20'), 99_000);

        self::assertFalse($performance->reliable);
        self::assertNull($performance->kilometers);
        self::assertSame(NeumaticPerformance::INSUFFICIENT_DATA, $performance->describe());
        self::assertNull($tire->totalKilometers());
    }

    public function testANewMountingCannotStartBeforeTheLastRemoval(): void
    {
        $tire = $this->newTire();
        $tire->mount(10, $this->position(), $this->at('2026-11-03'), 100_000);
        $tire->remove($this->at('2026-11-20'), 110_000);

        $this->expectException(DomainException::class);

        $tire->mount(11, $this->position(), $this->at('2026-11-19'), 40_000);
    }

    public function testTotalKilometersOnlyAddsReliableMountings(): void
    {
        $tire = $this->newTire();
        $tire->mount(10, $this->position(), $this->at('2026-11-01'), 100_000);
        $tire->remove($this->at('2026-11-05'), 110_000);
        $tire->mount(11, $this->position(), $this->at('2026-11-06'), 50_000);
        $tire->remove($this->at('2026-11-08'), 49_000);
        $tire->mount(10, $this->position(), $this->at('2026-11-09'), 110_000);
        $tire->remove($this->at('2026-11-12'), 115_000);

        self::assertSame(15_000, $tire->totalKilometers());
    }

    public function testMovesToAnotherBranchOnlyWhenNotMounted(): void
    {
        $tire = $this->newTire();
        $tire->moveToBranch(2);
        self::assertSame(2, $tire->branchId());

        $tire->mount(10, $this->position(), $this->at('2026-11-03'), 100_000);

        $this->expectException(DomainException::class);

        $tire->moveToBranch(3);
    }

    public function testReconstitutionRejectsTwoActiveMountings(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Un neumático no puede estar colocado en dos lugares a la vez.');

        Neumatic::reconstitute(
            1,
            1,
            1,
            new NeumaticNumber('TSA 1'),
            null,
            null,
            null,
            '',
            null,
            NeumaticStatus::NEW,
            [
                new NeumaticMounting(10, $this->position(), $this->at('2026-11-01'), 100),
                new NeumaticMounting(11, $this->position(), $this->at('2026-11-02'), 200),
            ],
            [],
        );
    }

    public function testMountingRejectsNegativeKilometersAndMissingEquipment(): void
    {
        $this->expectException(DomainException::class);

        new NeumaticMounting(10, $this->position(), $this->at('2026-11-01'), -1);
    }

    public function testMountingNeedsAnEquipment(): void
    {
        $this->expectException(DomainException::class);

        new NeumaticMounting(0, $this->position(), $this->at('2026-11-01'), 100);
    }

    public function testPositionIsNormalizedAndComparedByValue(): void
    {
        $position = new NeumaticPosition(' e2 ', 'i', '1', ' ');

        self::assertSame('E2', $position->place);
        self::assertNull($position->button);
        self::assertTrue($position->equals(new NeumaticPosition('E2', 'I', '1')));
        self::assertFalse($position->equals(new NeumaticPosition('E2', 'D', '1')));
    }

    public function testPositionRejectsMissingOrTooLongParts(): void
    {
        $this->expectException(DomainException::class);

        new NeumaticPosition('E2', 'ID', '1');
    }

    private function newTire(): Neumatic
    {
        return Neumatic::register(1, 1, new NeumaticNumber('tsa 181917'), $this->at('2026-11-01'));
    }

    private function position(): NeumaticPosition
    {
        return new NeumaticPosition('E1', 'I', '1');
    }

    private function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 10:00:00');
    }
}
