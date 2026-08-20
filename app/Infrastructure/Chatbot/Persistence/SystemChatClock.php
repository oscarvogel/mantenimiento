<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Persistence;

use App\Application\Chatbot\Port\ChatClock;

final class SystemChatClock implements ChatClock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}