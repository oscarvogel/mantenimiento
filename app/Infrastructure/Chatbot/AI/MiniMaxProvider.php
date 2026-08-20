<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\AI;

use App\Application\Chatbot\Port\AIProvider;
use App\Application\Chatbot\Port\AIResponse;
use App\Domain\Chatbot\ChatError;
use App\Domain\Chatbot\ToolDefinition;
use App\Infrastructure\Chatbot\AI\DTOs\MiniMaxRequest;
use App\Infrastructure\Chatbot\AI\DTOs\MiniMaxResponse;

final class MiniMaxProvider implements AIProvider
{
    private const ENDPOINT = 'https://api.minimax.chat/v1/text/chatcompletion_pro';

    public function __construct(
        private readonly AIProviderConfig $config,
    ) {}

    public function sendMessage(array $messages, array $tools = []): AIResponse
    {
        return $this->call($messages, $tools, stream: false);
    }

    public function sendMessageStreaming(array $messages, array $tools = [], callable $onChunk = null): AIResponse
    {
        return $this->call($messages, $tools, stream: true, onChunk: $onChunk);
    }

    private function call(array $messages, array $tools, bool $stream, callable $onChunk = null): AIResponse
    {
        if (! $this->config->enabled) {
            throw ChatError::providerError('El chatbot está deshabilitado. Configure ai.enabled=true en .env');
        }

        $functionTools = array_map(fn(ToolDefinition $t) => $t->toFunctionCallingFormat(), $tools);

        $request = new MiniMaxRequest(
            model: $this->config->model,
            messages: $messages,
            tools: $functionTools,
            stream: $stream,
        );

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request->toArray(), JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => $this->config->timeoutSeconds,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            throw ChatError::providerError("Error de conexión: {$error}");
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if ($httpCode !== 200) {
            $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
            throw ChatError::providerError($msg);
        }

        $response = MiniMaxResponse::fromArray($data);

        if ($onChunk !== null && $response->content !== '') {
            $onChunk($response->content);
        }

        return new AIResponse(
            content: $response->content,
            toolCalls: $response->toolCalls,
            tokensUsed: $response->tokensUsed,
        );
    }
}
