<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\AI\DTOs;

final class MiniMaxRequest
{
    public function __construct(
        public readonly string $model,
        /** @var array<int, array<string, mixed>> */
        public readonly array $messages,
        /** @var array<int, array<string, mixed>> */
        public readonly array $tools = [],
        public readonly float $temperature = 0.7,
        public readonly int $maxTokens = 2048,
        public readonly bool $stream = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'model' => $this->model,
            'messages' => $this->messages,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'stream' => $this->stream,
        ];

        if ($this->tools !== []) {
            $data['tools'] = $this->tools;
        }

        return $data;
    }
}
