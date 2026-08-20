<?php

declare(strict_types=1);

namespace App\Domain\Chatbot;

use DateTimeImmutable;

final class Message
{
    private function __construct(
        public readonly ?int $id,
        public readonly int $conversationId,
        public readonly string $role,
        public readonly string $content,
        public readonly ?array $toolCalls,
        public readonly ?string $toolCallId,
        public readonly ?int $tokensUsed,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    public static function user(int $conversationId, string $content): self
    {
        return new self(null, $conversationId, 'user', $content, null, null, null, new DateTimeImmutable());
    }

    public static function assistant(int $conversationId, string $content, ?int $tokensUsed = null): self
    {
        return new self(null, $conversationId, 'assistant', $content, null, null, $tokensUsed, new DateTimeImmutable());
    }

    public static function system(int $conversationId, string $content): self
    {
        return new self(null, $conversationId, 'system', $content, null, null, null, new DateTimeImmutable());
    }

    public static function tool(int $conversationId, string $toolCallId, string $name, array $result): self
    {
        $encoded = json_encode(['name' => $name, 'result' => $result], JSON_THROW_ON_ERROR);
        return new self(null, $conversationId, 'tool', $encoded, null, $toolCallId, null, new DateTimeImmutable());
    }

    public static function reconstitute(
        int $id,
        int $conversationId,
        string $role,
        string $content,
        ?array $toolCalls,
        ?string $toolCallId,
        ?int $tokensUsed,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $conversationId, $role, $content, $toolCalls, $toolCallId, $tokensUsed, $createdAt);
    }
}