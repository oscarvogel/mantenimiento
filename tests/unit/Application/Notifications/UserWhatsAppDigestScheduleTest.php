<?php

declare(strict_types=1);

use App\Application\Notifications\UserWhatsAppDigestSchedule;
use PHPUnit\Framework\TestCase;

final class UserWhatsAppDigestScheduleTest extends TestCase
{
    public function testWeekdayUsesEightAmSlotEvenIfCycleRunsLater(): void
    {
        $schedule = new UserWhatsAppDigestSchedule();

        self::assertSame(
            '2026-10-07 08:00:00',
            $schedule->slot(new DateTimeImmutable('2026-10-07 07:15:00'), '08:00')?->format('Y-m-d H:i:s'),
        );
        self::assertSame(
            '2026-10-07 08:00:00',
            $schedule->slot(new DateTimeImmutable('2026-10-07 10:30:00'), '08:00')?->format('Y-m-d H:i:s'),
        );
    }

    public function testSaturdayAndSundayHaveNoDigestSlot(): void
    {
        $schedule = new UserWhatsAppDigestSchedule();

        self::assertNull($schedule->slot(new DateTimeImmutable('2026-10-10 08:00:00'), '08:00'));
        self::assertNull($schedule->slot(new DateTimeImmutable('2026-10-11 08:00:00'), '08:00'));
    }

    public function testInvalidTimeFallsBackToEightAm(): void
    {
        $next = (new UserWhatsAppDigestSchedule())->slot(new DateTimeImmutable('2026-10-07 12:00:00'), 'invalid');

        self::assertSame('2026-10-07 08:00:00', $next?->format('Y-m-d H:i:s'));
    }
}
