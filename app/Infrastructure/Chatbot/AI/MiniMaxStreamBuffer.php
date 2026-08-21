<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\AI;

/**
 * Acumula chunks de un SSE "data: {json}" propio de MiniMax/OpenAI/compatibles.
 *
 * parsea cada bloque JSON a medida que llega y dispara $onChunk con el delta
 * de contenido, además de ir recolectando tool_calls y token usage cuando
 * llegan al final del stream.
 */
final class MiniMaxStreamBuffer
{
    private string $buffer = '';

    /** @var array<int, array<string, mixed>> */
    private array $toolCalls = [];

    private string $contentAccumulated = '';

    private ?int $tokens = null;

    private bool $closed = false;

    /**
     * @param callable(string): void $onChunk recibe cada delta de texto
     */
    public function __construct(private $onChunk)
    {
    }

    public function append(string $chunk): void
    {
        if ($this->closed) {
            return;
        }

        $this->buffer .= $chunk;

        // Procesar cada bloque \n\n
        while (($sep = strpos($this->buffer, "\n\n")) !== false) {
            $block = substr($this->buffer, 0, $sep);
            $this->buffer = substr($this->buffer, $sep + 2);
            $this->processBlock($block);
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->buffer !== '') {
            $this->processBlock($this->buffer);
            $this->buffer = '';
        }
    }

    private function processBlock(string $block): void
    {
        $block = trim($block);
        if ($block === '') {
            return;
        }

        foreach (explode("\n", $block) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! str_starts_with($line, 'data:')) {
                continue;
            }
            $payload = trim(substr($line, 5));
            if ($payload === '' || $payload === '[DONE]') {
                continue;
            }

            try {
                $event = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }

            $delta = $event['choices'][0]['delta'] ?? null;
            if (! is_array($delta)) {
                continue;
            }

            if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
                $this->contentAccumulated .= $delta['content'];
                ($this->onChunk)($delta['content']);
            }

            if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tcDelta) {
                    $index = (int) ($tcDelta['index'] ?? 0);
                    if (! isset($this->toolCalls[$index])) {
                        $this->toolCalls[$index] = [
                            'id' => '',
                            'name' => '',
                            'arguments' => '',
                        ];
                    }
                    if (isset($tcDelta['id'])) {
                        $this->toolCalls[$index]['id'] = (string) $tcDelta['id'];
                    }
                    if (isset($tcDelta['function']['name'])) {
                        $this->toolCalls[$index]['name'] = (string) $tcDelta['function']['name'];
                    }
                    if (isset($tcDelta['function']['arguments'])) {
                        $this->toolCalls[$index]['arguments'] .= (string) $tcDelta['function']['arguments'];
                    }
                }
            }

            if (isset($event['usage']['total_tokens'])) {
                $this->tokens = (int) $event['usage']['total_tokens'];
            }
        }
    }

    public function accumulatedContent(): string
    {
        $this->close();
        return $this->contentAccumulated;
    }

    /** @return array<int, array<string, mixed>> */
    public function accumulatedToolCalls(): array
    {
        $this->close();
        $calls = [];
        foreach ($this->toolCalls as $tc) {
            $args = $tc['arguments'] === '' ? [] : json_decode($tc['arguments'], true);
            $calls[] = [
                'id' => $tc['id'],
                'name' => $tc['name'],
                'arguments' => is_array($args) ? $args : [],
            ];
        }
        return $calls;
    }

    public function accumulatedTokens(): ?int
    {
        $this->close();
        return $this->tokens;
    }
}
