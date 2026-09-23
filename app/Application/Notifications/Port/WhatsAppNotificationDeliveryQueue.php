<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Domain\Notifications\NotifiableEvent;

interface WhatsAppNotificationDeliveryQueue
{
    public function scheduleDriverForEvent(NotifiableEvent $event): void;

    public function scheduleWeeklyReadingReminders(bool $force = false, ?string $testKey = null): int;

    /** @return list<array<string,mixed>> */
    public function due(int $limit): array;

    public function accepted(int $deliveryId, string $messageId, string $status): void;

    /** @return list<array<string,mixed>> */
    public function awaitingConfirmation(int $limit): array;

    public function reconcileStatus(int $deliveryId, string $status, ?string $providerMessageId = null, ?string $error = null): void;

    public function skipped(int $deliveryId, string $reason): void;

    public function failed(int $deliveryId, string $error, bool $retryable): void;
}
