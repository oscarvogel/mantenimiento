<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\WorkOrders;

use App\Domain\WorkOrders\EquipmentAvailability;
use PHPUnit\Framework\TestCase;

final class EquipmentAvailabilityTest extends TestCase
{
    public function testWithoutOpenOrdersTheEquipmentIsOperative(): void
    {
        self::assertSame(EquipmentAvailability::OPERATIVE, EquipmentAvailability::ofOpenOrders([]));
    }

    public function testAnOpenOrderWithoutStopKeepsItInTheShop(): void
    {
        self::assertSame(EquipmentAvailability::IN_SHOP, EquipmentAvailability::ofOpenOrders([self::order()]));
    }

    public function testOutOfServiceCheckStopsTheEquipment(): void
    {
        self::assertSame(EquipmentAvailability::STOPPED, EquipmentAvailability::ofOpenOrders([self::order(outOfService: true)]));
    }

    public function testAnOpenDowntimeStopsTheEquipment(): void
    {
        self::assertSame(
            EquipmentAvailability::STOPPED,
            EquipmentAvailability::ofOpenOrders([self::order(stopStartedAt: '2026-11-20 08:00:00')]),
        );
    }

    public function testAClosedDowntimeOnlyKeepsItInTheShop(): void
    {
        self::assertSame(
            EquipmentAvailability::IN_SHOP,
            EquipmentAvailability::ofOpenOrders([self::order(stopStartedAt: '2026-11-20 08:00:00', stopEndedAt: '2026-11-21 10:00:00')]),
        );
    }

    public function testOneStoppingOrderAmongSeveralStopsTheEquipment(): void
    {
        self::assertSame(
            EquipmentAvailability::STOPPED,
            EquipmentAvailability::ofOpenOrders([self::order(), self::order(outOfService: true)]),
        );
        self::assertTrue(EquipmentAvailability::isStopping(self::order(outOfService: true)));
        self::assertFalse(EquipmentAvailability::isStopping(self::order()));
    }

    /** @return array{outOfService:bool, stopStartedAt:?string, stopEndedAt:?string} */
    private static function order(bool $outOfService = false, ?string $stopStartedAt = null, ?string $stopEndedAt = null): array
    {
        return ['outOfService' => $outOfService, 'stopStartedAt' => $stopStartedAt, 'stopEndedAt' => $stopEndedAt];
    }
}
