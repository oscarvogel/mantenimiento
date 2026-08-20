<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Domain\Notifications\NotifiableEvent;

interface CompanyNotificationDeliveryQueue
{
    public function schedule(NotifiableEvent $event, ?string $recipient): void;

    /** @return list<array<string,mixed>> */
    public function due(int $limit): array;

    public function delivered(int $deliveryId): void;

    public function failed(int $deliveryId, string $error, bool $retryable): void;
}
