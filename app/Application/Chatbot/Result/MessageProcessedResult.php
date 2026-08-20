<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Result;

use App\Domain\Chatbot\Message;

final class MessageProcessedResult
{
    public function __construct(
        /** @var Message[] */
        public readonly array $messages,
        /** @var array<int, array<string, mixed>> */
        public readonly array $pendingToolCalls = [],
        public readonly bool $streaming = false,
    ) {}
}
