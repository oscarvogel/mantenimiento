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
        /** @var array<string, mixed>|null */
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

    /**
     * Construye un mensaje `tool` con la metadata necesaria para auditoría:
     * argumentos invocados, resultado y flag de éxito. El campo `content`
     * contiene una versión legible para mostrar en la conversación, mientras
     * que `tool_calls` (JSON) guarda la estructura para análisis.
     *
     * @param array<string, mixed>      $arguments
     * @param array<string, mixed>|list $result
     */
    public static function tool(
        int $conversationId,
        string $toolCallId,
        string $toolName,
        array $arguments,
        array $result,
        bool $success = true,
        ?string $errorMessage = null,
    ): self {
        $payload = [
            'name' => $toolName,
            'arguments' => $arguments,
            'result' => $result,
            'success' => $success,
        ];
        if ($errorMessage !== null) {
            $payload['error'] = $errorMessage;
        }

        $readable = $success
            ? sprintf('[%s ejecutado correctamente]', $toolName)
            : sprintf('[%s falló: %s]', $toolName, $errorMessage ?? 'error desconocido');

        return new self(
            null,
            $conversationId,
            'tool',
            $readable,
            $payload,
            $toolCallId,
            null,
            new DateTimeImmutable(),
        );
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
