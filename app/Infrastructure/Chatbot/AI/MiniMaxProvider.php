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
    public function __construct(
        private readonly AIProviderConfig $config,
    ) {}

    public function sendMessage(array $messages, array $tools = []): AIResponse
    {
        return $this->call($messages, $tools, stream: false, onChunk: null);
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
        if ($stream && $onChunk === null) {
            throw new \InvalidArgumentException('sendMessageStreaming requiere un callback $onChunk.');
        }

        $functionTools = array_map(fn (ToolDefinition $t) => $t->toFunctionCallingFormat(), $tools);

        $request = new MiniMaxRequest(
            model: $this->config->model,
            messages: $messages,
            tools: $functionTools,
            stream: $stream,
        );

        $payload = json_encode($request->toArray(), JSON_THROW_ON_ERROR);

        $headers = [
            'Authorization: Bearer ' . $this->config->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($stream) {
            $headers[] = 'Accept: text/event-stream';
        }

        $streamBuffer = new MiniMaxStreamBuffer($onChunk);

        $ch = curl_init($this->config->baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => ! $stream,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $stream ? 0 : $this->config->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->config->timeoutSeconds,
        ]);

        if ($stream) {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $chunk) use ($streamBuffer): int {
                $streamBuffer->append($chunk);
                return strlen($chunk);
            });
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw ChatError::providerError("Error de conexión: {$error}");
        }
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $msg = $this->extractErrorMessage((string) $raw) ?? "HTTP {$httpCode}";
            throw ChatError::providerError($msg);
        }

        if ($stream) {
            $content = $streamBuffer->accumulatedContent();
            $toolCalls = $streamBuffer->accumulatedToolCalls();
            $tokensUsed = $streamBuffer->accumulatedTokens();
        } else {
            $data = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            $response = MiniMaxResponse::fromArray($data);
            $content = $response->content;
            $toolCalls = $response->toolCalls;
            $tokensUsed = $response->tokensUsed;
        }

        return new AIResponse(
            content: $content,
            toolCalls: $toolCalls,
            tokensUsed: $tokensUsed,
        );
    }

    private function extractErrorMessage(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return $data['error']['message'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
