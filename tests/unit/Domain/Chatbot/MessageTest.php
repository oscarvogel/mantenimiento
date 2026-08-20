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

    public function testCreateToolMessage(): void
    {
        $result = [['id' => 14, 'codigo' => 'CAM-014']];
        $msg = Message::tool(conversationId: 1, toolCallId: 'call_123', name: 'buscar_equipo', result: $result);

        $this->assertSame('tool', $msg->role);
        $this->assertSame('call_123', $msg->toolCallId);
        $decoded = json_decode($msg->content, true);
        $this->assertSame('buscar_equipo', $decoded['name']);
        $this->assertSame($result, $decoded['result']);
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