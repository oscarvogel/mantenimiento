<?php

declare(strict_types=1);

namespace App\Application\Notifications;

final readonly class NotificationRecipient
{
    public function __construct(
        public int $userId,
        public int $companyId,
        public string $email,
    ) {
    }
}
