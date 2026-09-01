<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Domain\Notifications\NotifiableEvent;

interface NotifiableEventPublisher
{
    public function publish(NotifiableEvent $event): void;
}
