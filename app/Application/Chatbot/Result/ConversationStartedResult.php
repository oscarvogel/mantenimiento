<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Result;

use App\Domain\Chatbot\Conversation;

final class ConversationStartedResult
{
    public function __construct(
        public readonly Conversation $conversation,
    ) {}
}
