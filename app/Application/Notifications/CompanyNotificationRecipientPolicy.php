<?php

declare(strict_types=1);

namespace App\Application\Notifications;

final readonly class CompanyNotificationRecipientPolicy
{
    public function resolve(?string $notificationEmail, ?string $generalEmail, bool $enabled): ?string
    {
        if (! $enabled) {
            return null;
        }

        foreach ([$notificationEmail, $generalEmail] as $candidate) {
            $email = trim((string) $candidate);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                return $email;
            }
        }

        return null;
    }
}
