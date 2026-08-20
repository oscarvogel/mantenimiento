<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\SSE;

use CodeIgniter\HTTP\ResponseInterface;

final class StreamingResponse
{
    public function __construct(
        private readonly ResponseInterface $response,
    ) {}

    public function sendHeaders(): void
    {
        $this->response->setHeader('Content-Type', 'text/event-stream')
            ->setHeader('Cache-Control', 'no-cache')
            ->setHeader('Connection', 'keep-alive')
            ->setHeader('X-Accel-Buffering', 'no');
    }

    public function sendEvent(string $event, string $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data, JSON_THROW_ON_ERROR) . "\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    public function sendChunk(string $text): void
    {
        $this->sendEvent('chunk', $text);
    }

    public function sendToolCall(array $toolCall): void
    {
        $this->sendEvent('tool_call', json_encode($toolCall, JSON_THROW_ON_ERROR));
    }

    public function sendDone(): void
    {
        $this->sendEvent('done', '');
    }

    public function sendError(string $message): void
    {
        $this->sendEvent('error', $message);
    }
}
