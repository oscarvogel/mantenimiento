<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Application\Notifications\NotificationRecipient;
use App\Domain\Notifications\NotifiableEvent;

interface NotificationRecipientResolver
{
    /** @return list<NotificationRecipient> */
    public function resolve(NotifiableEvent $event): array;
}
