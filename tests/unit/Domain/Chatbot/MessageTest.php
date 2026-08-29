<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Chatbot;

use App\Domain\Chatbot\Message;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function testCreateUserMessage(): void
    {
        $msg = Message::user(conversationId: 1, content: 'Hola');

        $this->assertNull($msg->id);
        $this->assertSame(1, $msg->conversationId);
        $this->assertSame('user', $msg->role);
        $this->assertSame('Hola', $msg->content);
        $this->assertNull($msg->toolCalls);
        $this->assertNull($msg->toolCallId);
    }

    public function testCreateAssistantMessage(): void
    {
        $msg = Message::assistant(conversationId: 1, content: 'Hola, ¿en qué puedo ayudarte?');

        $this->assertSame('assistant', $msg->role);
    }

    public function testCreateToolMessagePersistsStructuredMetadata(): void
    {
        $result = [['id' => 14, 'codigo' => 'CAM-014']];
        $msg = Message::tool(
            conversationId: 1,
            toolCallId: 'call_123',
            toolName: 'buscar_equipo',
            arguments: ['query' => 'CAM-014'],
            result: $result,
            success: true,
        );

        $this->assertSame('tool', $msg->role);
        $this->assertSame('call_123', $msg->toolCallId);
        $this->assertIsArray($msg->toolCalls);
        $this->assertSame('buscar_equipo', $msg->toolCalls['name']);
        $this->assertSame(['query' => 'CAM-014'], $msg->toolCalls['arguments']);
        $this->assertSame($result, $msg->toolCalls['result']);
        $this->assertTrue($msg->toolCalls['success']);
        $this->assertArrayNotHasKey('error', $msg->toolCalls);
    }

    public function testToolFailureMessageIncludesErrorDetail(): void
    {
        $msg = Message::tool(
            conversationId: 1,
            toolCallId: 'call_err',
            toolName: 'registrar_lectura',
            arguments: ['equipmentId' => 14, 'kilometers' => -5],
            result: [],
            success: false,
            errorMessage: 'Los kilómetros no pueden ser negativos.',
        );

        $this->assertFalse($msg->toolCalls['success']);
        $this->assertSame('Los kilómetros no pueden ser negativos.', $msg->toolCalls['error']);
        $this->assertStringContainsString('registrar_lectura', $msg->content);
    }

    public function testReconstituteFromDb(): void
    {
        $msg = Message::reconstitute(
            id: 42,
            conversationId: 1,
            role: 'assistant',
            content: 'Respuesta',
            toolCalls: null,
            toolCallId: null,
            tokensUsed: 150,
            createdAt: new \DateTimeImmutable('2026-08-20 14:00:00'),
        );

        $this->assertSame(42, $msg->id);
        $this->assertSame(150, $msg->tokensUsed);
    }
}
