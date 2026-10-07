<?php

declare(strict_types=1);

use App\Application\Notifications\UserWhatsAppDigestSchedule;
use PHPUnit\Framework\TestCase;

final class UserWhatsAppDigestScheduleTest extends TestCase
{
    public function testBeforeEightSchedulesSameBusinessDay(): void
    {
        $next = (new UserWhatsAppDigestSchedule())->next(new DateTimeImmutable('2026-10-07 07:15:00'), '08:00');

        self::assertSame('2026-10-07 08:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function testAfterEightSchedulesNextBusinessDay(): void
    {
        $next = (new UserWhatsAppDigestSchedule())->next(new DateTimeImmutable('2026-10-07 08:05:00'), '08:00');

        self::assertSame('2026-10-08 08:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function testFridayAfterEightSkipsWeekend(): void
    {
        $next = (new UserWhatsAppDigestSchedule())->next(new DateTimeImmutable('2026-10-09 09:00:00'), '08:00');

        self::assertSame('2026-10-12 08:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function testWeekendSchedulesMonday(): void
    {
        $next = (new UserWhatsAppDigestSchedule())->next(new DateTimeImmutable('2026-10-10 10:00:00'), '08:00');

        self::assertSame('2026-10-12 08:00:00', $next->format('Y-m-d H:i:s'));
    }
}
