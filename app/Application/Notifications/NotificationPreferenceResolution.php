<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Domain\Notifications\NotificationPreference;

final class NotificationPreferenceResolution
{
    public function resolve(?NotificationPreference $userOverride, ?NotificationPreference $roleDefault): NotificationPreference
    {
        return $userOverride ?? $roleDefault ?? NotificationPreference::defaults();
    }
}
