<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Command;

final readonly class SendMessageCommand
{
    public function __construct(
        public int $conversationId,
        public string $content,
        /** @var array<int, array<string, mixed>>|null */
        public ?array $confirmedToolCalls = null,
        public bool $streaming = false,
    ) {}
}
