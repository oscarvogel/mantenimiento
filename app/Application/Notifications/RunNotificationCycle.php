<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Notifications\Port\NotificationClock;
use App\Application\PreventiveMaintenance\DetectOverduePlansAutomatically;

final readonly class RunNotificationCycle
{
    public function __construct(
        private DetectOverduePlansAutomatically $detectOverdue,
        private CollectOperationalNotifications $collector,
        private RunNotificationDispatch $dispatch,
        private NotificationClock $clock,
    ) {
    }

    /** @return array{execution_key:string,overdue:mixed,collected:array{events:int,created:int,duplicates:int},dispatched:array<string,int>} */
    public function execute(?string $executionKey = null, int $lockTtl = 900): array
    {
        $key = trim((string) $executionKey);
        if ($key === '') {
            $key = $this->clock->now()->format('Y-m-d-H');
        }

        return [
            'execution_key' => $key,
            'overdue' => $this->detectOverdue->execute(),
            'collected' => $this->collector->execute(),
            'dispatched' => $this->dispatch->execute($key, $lockTtl),
        ];
    }
}
