<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\SSE;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Helper para emitir Server-Sent Events estándar para chat.
 *
 * Formato esperado por el frontend (texto/event-stream):
 *   event: chunk\ndata: "...texto..."\n\n
 *   event: pending_tools\ndata: {...}\n\n
 *   event: error\ndata: "mensaje"\n\n
 *   event: done\ndata: \n\n
 */
final class StreamingResponse
{
    public function __construct(
        private readonly ResponseInterface $response,
    ) {}

    public function sendHeaders(): void
    {
        $this->response
            ->setHeader('Content-Type', 'text/event-stream; charset=utf-8')
            ->setHeader('Cache-Control', 'no-cache, no-transform')
            ->setHeader('Connection', 'keep-alive')
            ->setHeader('X-Accel-Buffering', 'no')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }

    public function sendChunk(string $text): void
    {
        $this->write("event: chunk\ndata: " . $this->escape($text) . "\n\n");
    }

    /**
     * @param array<int, array<string, mixed>>|array<string, mixed> $toolCalls
     */
    public function sendPendingTools(array $toolCalls): void
    {
        $encoded = json_encode($toolCalls, JSON_THROW_ON_ERROR);
        $this->write("event: pending_tools\ndata: {$encoded}\n\n");
    }

    public function sendDone(): void
    {
        $this->write("event: done\ndata: \n\n");
    }

    public function sendError(string $message): void
    {
        $this->write("event: error\ndata: " . $this->escape($message) . "\n\n");
    }

    private function write(string $payload): void
    {
        echo $payload;
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    private function escape(string $text): string
    {
        // SSE requiere escape de \n a \\n dentro del data. Mantener acentos y caracteres.
        return str_replace(["\r", "\n"], ['\\r', '\\n'], $text);
    }
}
