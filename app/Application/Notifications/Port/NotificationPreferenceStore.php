<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Domain\Notifications\NotificationPreference;

interface NotificationPreferenceStore
{
    public function resolve(int $userId, string $eventType): NotificationPreference;

    public function save(int $userId, string $eventType, NotificationPreference $preference): void;

    /** @return array<string,NotificationPreference> */
    public function allForUser(int $userId): array;
}
