<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Domain\Notifications\NotifiableEvent;

interface CompanyNotificationDeliveryQueue
{
    public function scheduleCompany(NotifiableEvent $event): void;

    /** @return list<array<string,mixed>> */
    public function dueCompany(int $limit): array;

    public function deliveredCompany(int $deliveryId): void;

    public function failedCompany(int $deliveryId, string $error, bool $retryable): void;
}
