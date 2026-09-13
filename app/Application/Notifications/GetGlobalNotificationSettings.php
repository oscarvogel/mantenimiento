<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Notifications\Port\GlobalNotificationSettingsStore;

final readonly class GetGlobalNotificationSettings
{
    public function __construct(private GlobalNotificationSettingsStore $store)
    {
    }

    /** @return array<string,mixed> */
    public function execute(): array
    {
        return $this->store->get();
    }
}
