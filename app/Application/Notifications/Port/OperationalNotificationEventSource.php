<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Domain\Notifications\NotifiableEvent;

interface OperationalNotificationEventSource
{
    /** @return list<NotifiableEvent> */
    public function collect(): array;
}
