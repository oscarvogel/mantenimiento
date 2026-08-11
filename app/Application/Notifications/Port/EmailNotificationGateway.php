<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

interface EmailNotificationGateway
{
    /** @param list<array<string,mixed>> $notifications */
    public function sendDigest(string $recipient, array $notifications): void;
}
