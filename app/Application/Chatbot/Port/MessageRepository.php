<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Port;

use App\Domain\Chatbot\Message;

interface MessageRepository
{
    public function append(Message $message): int;
    /** @return Message[] */
    public function findForConversation(int $conversationId, int $limit = 50, int $offset = 0): array;
    public function countForConversation(int $conversationId): int;
}
