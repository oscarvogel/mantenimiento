<?php

declare(strict_types=1);

namespace App\Domain\Chatbot;

use DateTimeImmutable;

final class Conversation
{
    private function __construct(
        public readonly ?int $id,
        public readonly int $usuarioId,
        public readonly int $empresaId,
        public readonly ?string $titulo,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {}

    public static function create(int $usuarioId, int $empresaId, ?string $titulo = null): self
    {
        $now = new DateTimeImmutable();
        return new self(
            id: null,
            usuarioId: $usuarioId,
            empresaId: $empresaId,
            titulo: $titulo,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public static function reconstitute(
        int $id,
        int $usuarioId,
        int $empresaId,
        ?string $titulo,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $usuarioId, $empresaId, $titulo, $createdAt, $updatedAt);
    }

    public function withTitle(string $titulo): self
    {
        return new self($this->id, $this->usuarioId, $this->empresaId, $titulo, $this->createdAt, $this->updatedAt);
    }
}