<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\AI;

final class AIProviderConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $provider,
        public readonly string $apiKey,
        public readonly string $model,
        public readonly int $timeoutSeconds,
        public readonly int $contextWindowMessages,
        public readonly int $rateLimitPerMinute,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            enabled: filter_var(env('ai.enabled', false), FILTER_VALIDATE_BOOL),
            provider: env('ai.provider', 'minimax'),
            apiKey: env('ai.apiKey', ''),
            model: env('ai.model', ''),
            timeoutSeconds: (int) env('ai.timeoutSeconds', 30),
            contextWindowMessages: (int) env('ai.contextWindowMessages', 20),
            rateLimitPerMinute: (int) env('ai.rateLimitPerMinute', 60),
        );
    }
}
