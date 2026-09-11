<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

interface WhatsAppNotificationGateway
{
    /** @return array{messageId:string,status:string} */
    public function sendText(
        string $phone,
        string $message,
        string $externalRef,
        ?string $actorId = null,
        ?string $actorName = null,
    ): array;

    public function available(): bool;

    public function normalizePhone(string $phone): ?string;
}
