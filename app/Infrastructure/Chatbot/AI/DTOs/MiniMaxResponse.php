<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\AI\DTOs;

final class MiniMaxResponse
{
    public function __construct(
        public readonly string $content,
        /** @var array<int, array<string, mixed>> */
        public readonly array $toolCalls = [],
        public readonly ?int $tokensUsed = null,
        public readonly ?string $error = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $choice = $data['choices'][0] ?? null;
        $message = $choice['message'] ?? [];
        $toolCalls = [];

        foreach ($message['tool_calls'] ?? [] as $tc) {
            $args = json_decode($tc['function']['arguments'] ?? '{}', true);
            $toolCalls[] = [
                'id' => $tc['id'] ?? '',
                'name' => $tc['function']['name'] ?? '',
                'arguments' => is_array($args) ? $args : [],
            ];
        }

        return new self(
            content: $message['content'] ?? '',
            toolCalls: $toolCalls,
            tokensUsed: $data['usage']['total_tokens'] ?? null,
        );
    }
}
