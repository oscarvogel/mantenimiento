<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Command;

final class SendMessageCommand
{
    public function __construct(
        public readonly int $conversationId,
        public readonly string $content,
        public readonly ?array $confirmedToolCalls = null,
    ) {}
}
