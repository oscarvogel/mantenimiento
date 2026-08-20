<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Persistence;

use App\Application\Chatbot\Port\ConversationRepository;
use App\Domain\Chatbot\Conversation;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterConversationRepository implements ConversationRepository
{
    public function __construct(
        private readonly BaseConnection $database,
    ) {}

    public function save(Conversation $conversation): int
    {
        $this->database->table('conversaciones')->insert([
            'usuario_id' => $conversation->usuarioId,
            'empresa_id' => $conversation->empresaId,
            'titulo' => $conversation->titulo,
            'created_at' => $conversation->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $conversation->updatedAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->database->insertID();
    }

    public function find(int $id): ?Conversation
    {
        $row = $this->database->table('conversaciones')->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return Conversation::reconstitute(
            id: (int) $row['id'],
            usuarioId: (int) $row['usuario_id'],
            empresaId: (int) $row['empresa_id'],
            titulo: $row['titulo'],
            createdAt: new \DateTimeImmutable($row['created_at']),
            updatedAt: new \DateTimeImmutable($row['updated_at']),
        );
    }

    public function findByUser(int $usuarioId, int $empresaId, int $limit = 20, int $offset = 0): array
    {
        $rows = $this->database->table('conversaciones')
            ->where('usuario_id', $usuarioId)
            ->where('empresa_id', $empresaId)
            ->orderBy('updated_at', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        return array_map(fn($row) => Conversation::reconstitute(
            id: (int) $row['id'],
            usuarioId: (int) $row['usuario_id'],
            empresaId: (int) $row['empresa_id'],
            titulo: $row['titulo'],
            createdAt: new \DateTimeImmutable($row['created_at']),
            updatedAt: new \DateTimeImmutable($row['updated_at']),
        ), $rows);
    }
}
