<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Domain\Notifications\WebPushSubscription;

interface WebPushSubscriptionStore
{
    public function upsert(WebPushSubscription $subscription): int;
    public function deactivate(int $userId, string $endpoint): bool;
    /** @return list<array<string,mixed>> */
    public function activeForUser(int $userId): array;
    public function markInvalid(string $endpoint, string $error): void;
}
