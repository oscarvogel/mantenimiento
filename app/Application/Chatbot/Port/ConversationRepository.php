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

    /**
     * Devuelve la conversación solo si pertenece al usuario y empresa dados.
     * Si no existe o no coincide, devuelve null — usado por endpoints que
     * exponen `conversationId` al cliente (history, etc.).
     */
    public function findOwned(int $id, int $usuarioId, int $empresaId): ?Conversation;
}
