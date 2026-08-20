<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Port;

use App\Domain\Chatbot\Conversation;

interface ConversationRepository
{
    public function save(Conversation $conversation): int;
    public function find(int $id): ?Conversation;
    /** @return Conversation[] */
    public function findByUser(int $usuarioId, int $empresaId, int $limit = 20, int $offset = 0): array;
}
