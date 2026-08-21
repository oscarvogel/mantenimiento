<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\AI;

use InvalidArgumentException;

/**
 * Config del proveedor IA, leída desde .env.
 *
 * - `provider`: nombre del proveedor (ej. `minimax`, `openai`).
 * - `endpoints`: mapa de proveedor → URL base. Se elige en función de `provider`.
 * - El `apiKey` y el `model` nunca deben llegar al frontend.
 */
final class AIProviderConfig
{
    /** @var array<string, string> */
    private const ENDPOINTS = [
        'minimax' => 'https://api.minimax.chat/v1/text/chatcompletion_pro',
        'openai'  => 'https://api.openai.com/v1/chat/completions',
        'custom'  => '',
    ];

    public function __construct(
        public readonly bool $enabled,
        public readonly string $provider,
        public readonly string $apiKey,
        public readonly string $model,
        public readonly string $baseUrl,
        public readonly int $timeoutSeconds,
        public readonly int $contextWindowMessages,
        public readonly int $rateLimitPerMinute,
    ) {}

    public static function fromEnv(): self
    {
        $provider = strtolower(trim((string) env('ai.provider', 'minimax')));
        $customEndpoint = trim((string) env('ai.baseUrl', ''));

        $baseUrl = $customEndpoint !== ''
            ? $customEndpoint
            : (self::ENDPOINTS[$provider] ?? '');

        if ($baseUrl === '') {
            throw new InvalidArgumentException(
                "Proveedor IA desconocido: '{$provider}'. Configure ai.provider o ai.baseUrl en .env."
            );
        }

        return new self(
            enabled: filter_var(env('ai.enabled', false), FILTER_VALIDATE_BOOL),
            provider: $provider,
            apiKey: (string) env('ai.apiKey', ''),
            model: (string) env('ai.model', ''),
            baseUrl: $baseUrl,
            timeoutSeconds: (int) env('ai.timeoutSeconds', 30),
            contextWindowMessages: (int) env('ai.contextWindowMessages', 20),
            rateLimitPerMinute: (int) env('ai.rateLimitPerMinute', 60),
        );
    }

    public function supportsStreaming(): bool
    {
        return in_array($this->provider, ['minimax', 'openai', 'custom'], true);
    }
}
