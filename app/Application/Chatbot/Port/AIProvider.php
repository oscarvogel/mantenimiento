<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Port;

use App\Domain\Chatbot\ToolDefinition;

interface AIProvider
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @param ToolDefinition[] $tools
     * @return AIResponse
     */
    public function sendMessage(array $messages, array $tools = []): AIResponse;

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param ToolDefinition[] $tools
     * @param callable(string): void $onChunk
     * @return AIResponse
     */
    public function sendMessageStreaming(array $messages, array $tools = [], callable $onChunk = null): AIResponse;
}

final class AIResponse
{
    public function __construct(
        public readonly string $content,
        /** @var array<int, array<string, mixed>> */
        public readonly array $toolCalls = [],
        public readonly ?int $tokensUsed = null,
    ) {}
}
