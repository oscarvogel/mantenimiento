<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Domain\Notifications\DeliveryMode;
use App\Domain\Notifications\NotificationSeverity;

final class NotificationChannelPolicy
{
    public function shouldSchedule(DeliveryMode $mode, NotificationSeverity $severity, bool $available = true): bool
    {
        return $available && $mode !== DeliveryMode::DISABLED
            && ($mode !== DeliveryMode::CRITICAL_ONLY || $severity === NotificationSeverity::CRITICAL);
    }
}
