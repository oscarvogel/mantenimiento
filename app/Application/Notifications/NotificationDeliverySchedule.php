<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Domain\Notifications\DeliveryMode;
use DateTimeImmutable;

final class NotificationDeliverySchedule
{
    public function nextAttempt(DeliveryMode $mode, DateTimeImmutable $now, string $dailyRunTime): ?DateTimeImmutable
    {
        if ($mode !== DeliveryMode::DAILY_DIGEST) {
            return null;
        }
        $parts = array_map('intval', explode(':', $dailyRunTime, 2));
        $next = $now->setTime(max(0, min(23, $parts[0] ?? 7)), max(0, min(59, $parts[1] ?? 0)));
        return $next <= $now ? $next->modify('+1 day') : $next;
    }
}
