<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Chatbot;

use App\Application\Chatbot\Handler\ProcessMessageHandler;
use App\Application\Chatbot\Command\SendMessageCommand;
use App\Application\Chatbot\Port\AIProvider;
use App\Application\Chatbot\Port\AIResponse;
use App\Application\Chatbot\Port\ChatClock;
use App\Application\Chatbot\Port\ConversationRepository;
use App\Application\Chatbot\Port\MessageRepository;
use App\Application\Chatbot\Port\ToolExecutor;
use App\Application\Chatbot\Port\ToolRegistry;
use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\Conversation;
use App\Domain\Chatbot\ToolCallResult;
use App\Domain\Chatbot\ToolDefinition;
use App\Domain\Chatbot\Message;
use PHPUnit\Framework\TestCase;

final class ProcessMessageHandlerTest extends TestCase
{
    private function createActor(): ActorContext
    {
        return ActorContext::fromArray([
            'user_id' => 1,
            'company_id' => 1,
            'super_admin' => false,
            'all_company_branches' => false,
            'roles' => ['administrador'],
            'permissions' => ['chatbot.usar', 'equipos.ver', 'lecturas.cargar'],
            'branch_ids' => [1],
        ]);
    }

    public function testSimpleMessageWithoutToolCalls(): void
    {
        $msgRepo = $this->createMock(MessageRepository::class);
        $msgRepo->expects($this->any())->method('findForConversation')->willReturn([]);

        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('find')->willReturn(
            Conversation::reconstitute(1, 1, 1, null, new \DateTimeImmutable(), new \DateTimeImmutable())
        );

        $ai = $this->createMock(AIProvider::class);
        $ai->method('sendMessage')->willReturn(new AIResponse(content: 'Hola, ¿en qué puedo ayudarte?'));

        $handler = new ProcessMessageHandler(
            messages: $msgRepo,
            toolRegistry: new class implements ToolRegistry {
                public function all(): array { return []; }
                public function find(string $name): ?ToolDefinition { return null; }
            },
            aiProvider: $ai,
            toolExecutor: $this->createMock(ToolExecutor::class),
            clock: new class implements ChatClock {
                public function now(): \DateTimeImmutable { return new \DateTimeImmutable(); }
            },
            conversations: $convRepo,
        );

        $result = $handler->execute($this->createActor(), new SendMessageCommand(conversationId: 1, content: 'Hola'));

        $this->assertEmpty($result->pendingToolCalls);
        $this->assertCount(2, $result->messages);
        $this->assertSame('user', $result->messages[0]->role);
        $this->assertSame('assistant', $result->messages[1]->role);
    }

    public function testWriteToolCallsReturnPendingConfirmation(): void
    {
        $writeTool = ToolDefinition::write(
            name: 'registrar_lectura',
            description: 'Registra una lectura',
            parameters: ['equipmentId' => ['type' => 'integer']],
            permission: 'lecturas.cargar',
        );

        $msgRepo = $this->createMock(MessageRepository::class);
        $msgRepo->method('findForConversation')->willReturn([]);

        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('find')->willReturn(
            Conversation::reconstitute(1, 1, 1, null, new \DateTimeImmutable(), new \DateTimeImmutable())
        );

        $ai = $this->createMock(AIProvider::class);
        $ai->method('sendMessage')->willReturn(new AIResponse(
            content: '',
            toolCalls: [['id' => 'call_2', 'name' => 'registrar_lectura', 'arguments' => ['equipmentId' => 14]]],
        ));

        $registry = new class ($writeTool) implements ToolRegistry {
            public function __construct(private readonly ToolDefinition $tool) {}
            public function all(): array { return [$this->tool]; }
            public function find(string $name): ?ToolDefinition { return $name === $this->tool->name ? $this->tool : null; }
        };

        $handler = new ProcessMessageHandler(
            messages: $msgRepo,
            toolRegistry: $registry,
            aiProvider: $ai,
            toolExecutor: $this->createMock(ToolExecutor::class),
            clock: new class implements ChatClock {
                public function now(): \DateTimeImmutable { return new \DateTimeImmutable(); }
            },
            conversations: $convRepo,
        );

        $result = $handler->execute($this->createActor(), new SendMessageCommand(conversationId: 1, content: 'Cargá lectura'));

        $this->assertCount(1, $result->pendingToolCalls);
        $this->assertSame('registrar_lectura', $result->pendingToolCalls[0]['name']);
    }

    public function testReadToolCallsAreExecuted(): void
    {
        $readTool = ToolDefinition::read('buscar_equipo', 'Busca equipo', ['query' => ['type' => 'string']], 'equipos.ver');

        $msgRepo = $this->createMock(MessageRepository::class);
        $msgRepo->method('findForConversation')->willReturn([]);

        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('find')->willReturn(
            Conversation::reconstitute(1, 1, 1, null, new \DateTimeImmutable(), new \DateTimeImmutable())
        );

        $ai = $this->createMock(AIProvider::class);
        $ai->method('sendMessage')->willReturnOnConsecutiveCalls(
            new AIResponse(content: '', toolCalls: [['id' => 'call_1', 'name' => 'buscar_equipo', 'arguments' => ['query' => 'CAM-014']]]),
            new AIResponse(content: 'El CAM-014 es un Volvo FH16.'),
        );

        $executor = $this->createMock(ToolExecutor::class);
        $executor->expects($this->once())->method('execute')
            ->with('buscar_equipo', ['query' => 'CAM-014'], $this->anything())
            ->willReturn(ToolCallResult::success('call_1', 'buscar_equipo', [['codigo' => 'CAM-014']]));

        $registry = new class ($readTool) implements ToolRegistry {
            public function __construct(private readonly ToolDefinition $tool) {}
            public function all(): array { return [$this->tool]; }
            public function find(string $name): ?ToolDefinition { return $name === $this->tool->name ? $this->tool : null; }
        };

        $handler = new ProcessMessageHandler(
            messages: $msgRepo,
            toolRegistry: $registry,
            aiProvider: $ai,
            toolExecutor: $executor,
            clock: new class implements ChatClock {
                public function now(): \DateTimeImmutable { return new \DateTimeImmutable(); }
            },
            conversations: $convRepo,
        );

        $result = $handler->execute($this->createActor(), new SendMessageCommand(conversationId: 1, content: 'Buscá CAM-014'));

        $this->assertEmpty($result->pendingToolCalls);
        $this->assertCount(2, $result->messages);
    }
}
