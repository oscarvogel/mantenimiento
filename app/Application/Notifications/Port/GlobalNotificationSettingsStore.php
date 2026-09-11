<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

interface GlobalNotificationSettingsStore
{
    /** @return array<string,mixed> */
    public function get(): array;

    /** @param array<string,mixed> $settings */
    public function save(array $settings, int $actorId): void;
}
