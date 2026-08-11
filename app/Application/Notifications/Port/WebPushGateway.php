<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

interface WebPushGateway
{
    /** @return array{sent:int,expired:int,failed:int} */
    public function sendToUser(int $userId, string $title, string $summary, ?string $url): array;
}
