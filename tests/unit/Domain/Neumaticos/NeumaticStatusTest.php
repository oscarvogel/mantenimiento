<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Neumaticos;

use App\Domain\Neumaticos\NeumaticStatus;
use PHPUnit\Framework\TestCase;

final class NeumaticStatusTest extends TestCase
{
    public function testKeepsTheElevenStatesOfTheLegacySystem(): void
    {
        self::assertCount(11, NeumaticStatus::cases());
    }

    public function testFollowsTheRepairPath(): void
    {
        self::assertTrue(NeumaticStatus::NEW->canTransitionTo(NeumaticStatus::HALF_USED));
        self::assertTrue(NeumaticStatus::HALF_USED->canTransitionTo(NeumaticStatus::IN_REPAIR));
        self::assertTrue(NeumaticStatus::IN_REPAIR->canTransitionTo(NeumaticStatus::REPAIRED));
        foreach ([NeumaticStatus::VULCANIZED, NeumaticStatus::RETREADED, NeumaticStatus::DISCARDED, NeumaticStatus::TRANSFERRED] as $target) {
            self::assertTrue(NeumaticStatus::REPAIRED->canTransitionTo($target), $target->value);
        }
    }

    public function testFollowsTheSalePath(): void
    {
        self::assertTrue(NeumaticStatus::NEW->canTransitionTo(NeumaticStatus::FOR_SALE));
        self::assertTrue(NeumaticStatus::FOR_SALE->canTransitionTo(NeumaticStatus::SOLD));
    }

    public function testWriteOffOnlyComesFromRetreadedVulcanizedOrDiscarded(): void
    {
        $allowed = [NeumaticStatus::RETREADED, NeumaticStatus::VULCANIZED, NeumaticStatus::DISCARDED];

        foreach (NeumaticStatus::cases() as $status) {
            self::assertSame(
                in_array($status, $allowed, true),
                $status->canTransitionTo(NeumaticStatus::WRITTEN_OFF),
                $status->value,
            );
        }
    }

    public function testFinalStatesHaveNoWayOut(): void
    {
        foreach ([NeumaticStatus::WRITTEN_OFF, NeumaticStatus::TRANSFERRED, NeumaticStatus::SOLD] as $final) {
            self::assertTrue($final->isFinal(), $final->value);
            foreach (NeumaticStatus::cases() as $target) {
                self::assertFalse($final->canTransitionTo($target), $final->value . ' -> ' . $target->value);
            }
        }
        self::assertFalse(NeumaticStatus::NEW->isFinal());
    }

    public function testNoStateTransitionsToItself(): void
    {
        foreach (NeumaticStatus::cases() as $status) {
            self::assertFalse($status->canTransitionTo($status), $status->value);
        }
    }

    public function testOnlyRoadworthyTiresCanBeMounted(): void
    {
        $mountable = [NeumaticStatus::NEW, NeumaticStatus::HALF_USED, NeumaticStatus::REPAIRED, NeumaticStatus::RETREADED, NeumaticStatus::VULCANIZED];

        foreach (NeumaticStatus::cases() as $status) {
            self::assertSame(in_array($status, $mountable, true), $status->canBeMounted(), $status->value);
        }
    }

    public function testEveryStateHasAHumanLabel(): void
    {
        self::assertSame('1/2 uso', NeumaticStatus::HALF_USED->label());
        foreach (NeumaticStatus::cases() as $status) {
            self::assertNotSame('', $status->label());
        }
    }
}
