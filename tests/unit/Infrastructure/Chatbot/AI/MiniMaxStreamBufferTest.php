<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Chatbot\AI;

use App\Infrastructure\Chatbot\AI\MiniMaxStreamBuffer;
use PHPUnit\Framework\TestCase;

final class MiniMaxStreamBufferTest extends TestCase
{
    public function testAccumulatesContentAcrossChunks(): void
    {
        $chunks = [];
        $buffer = new MiniMaxStreamBuffer(static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });

        $buffer->append(
            "event: message\n"
            . 'data: {"choices":[{"delta":{"content":"Hol"}}]}' . "\n\n"
        );
        $buffer->append(
            'data: {"choices":[{"delta":{"content":"a"}}]}' . "\n\n"
        );
        $buffer->append(
            'data: {"choices":[{"delta":{"content":" mundo"}}]}' . "\n\n"
        );

        $this->assertSame(['Hol', 'a', ' mundo'], $chunks);
        $this->assertSame('Hola mundo', $buffer->accumulatedContent());
    }

    public function testIgnoresDoneSentinel(): void
    {
        $buffer = new MiniMaxStreamBuffer(static function (string $chunk): void {
        });
        $buffer->append('data: {"choices":[{"delta":{"content":"OK"}}]}' . "\n\n");
        $buffer->append("data: [DONE]\n\n");

        $this->assertSame('OK', $buffer->accumulatedContent());
    }

    public function testAccumulatesToolCallsFromIncrementalArguments(): void
    {
        $buffer = new MiniMaxStreamBuffer(static function (string $chunk): void {
        });

        $buffer->append(
            'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_1","function":{"name":"buscar"}}]}}]}' . "\n\n"
        );
        $buffer->append(
            'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"q"}}]}}]}' . "\n\n"
        );
        $buffer->append(
            'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"uery\":\"CAM\"}"}}]}}]}' . "\n\n"
        );
        $buffer->append('data: [DONE]' . "\n\n");

        $calls = $buffer->accumulatedToolCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('call_1', $calls[0]['id']);
        $this->assertSame('buscar', $calls[0]['name']);
        $this->assertSame(['query' => 'CAM'], $calls[0]['arguments']);
    }

    public function testCapturesTokensFromFinalUsage(): void
    {
        $buffer = new MiniMaxStreamBuffer(static function (string $chunk): void {
        });

        $buffer->append('data: {"choices":[{"delta":{"content":"hola"}}]}' . "\n\n");
        $buffer->append(
            'data: {"choices":[{"delta":{}}],"usage":{"total_tokens":42}}' . "\n\n"
        );
        $buffer->append('data: [DONE]' . "\n\n");

        $this->assertSame(42, $buffer->accumulatedTokens());
    }
}
