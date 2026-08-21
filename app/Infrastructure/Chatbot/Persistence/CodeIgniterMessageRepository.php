<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Persistence;

use App\Application\Chatbot\Port\MessageRepository;
use App\Domain\Chatbot\Message;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterMessageRepository implements MessageRepository
{
    public function __construct(
        private readonly BaseConnection $database,
    ) {}

    public function append(Message $message): int
    {
        $this->database->table('mensajes')->insert([
            'conversacion_id' => $message->conversationId,
            'role' => $message->role,
            'content' => $message->content,
            'tool_calls' => $message->toolCalls !== null ? json_encode($message->toolCalls) : null,
            'tool_call_id' => $message->toolCallId,
            'tokens_used' => $message->tokensUsed,
            'created_at' => $message->createdAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->database->insertID();
    }

    public function findForConversation(int $conversationId, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->database->table('mensajes')
            ->where('conversacion_id', $conversationId)
            ->orderBy('created_at', 'ASC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        return array_map(fn ($row) => Message::reconstitute(
            id: (int) $row['id'],
            conversationId: (int) $row['conversacion_id'],
            role: $row['role'],
            content: $row['content'],
            toolCalls: $this->decodeToolCalls($row['tool_calls'] ?? null),
            toolCallId: $row['tool_call_id'],
            tokensUsed: $row['tokens_used'] !== null ? (int) $row['tokens_used'] : null,
            createdAt: new \DateTimeImmutable($row['created_at']),
        ), $rows);
    }

    /**
     * Decodifica JSON de tool_calls de manera defensiva: si el JSON está
     * corrupto o no es un array asociativo devuelve null para evitar
     * propagar basura al dominio.
     *
     * @return array<string, mixed>|null
     */
    private function decodeToolCalls(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    public function countForConversation(int $conversationId): int
    {
        return (int) $this->database->table('mensajes')
            ->where('conversacion_id', $conversationId)
            ->countAllResults();
    }
}
