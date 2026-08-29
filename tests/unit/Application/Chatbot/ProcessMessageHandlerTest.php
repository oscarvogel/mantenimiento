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
    private function createActor(int $userId = 1, int $companyId = 1): ActorContext
    {
        return ActorContext::fromArray([
            'user_id' => $userId,
            'company_id' => $companyId,
            'super_admin' => false,
            'all_company_branches' => false,
            'roles' => ['administrador'],
            'permissions' => ['chatbot.usar', 'equipos.ver', 'lecturas.cargar'],
            'branch_ids' => [1],
        ]);
    }

    private function createSuperAdmin(int $userId = 99): ActorContext
    {
        return ActorContext::fromArray([
            'user_id' => $userId,
            'company_id' => null,
            'super_admin' => true,
            'all_company_branches' => false,
            'roles' => ['superadmin'],
            'permissions' => ['chatbot.usar', 'equipos.ver', 'lecturas.cargar'],
            'branch_ids' => [],
        ]);
    }

    private function conversationOwnedBy(int $convId, int $userId, int $companyId): Conversation
    {
        return Conversation::reconstitute(
            $convId,
            $userId,
            $companyId,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
        );
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

    public function testConfirmedToolCallsAreExecutedWithoutCallingProviderAgain(): void
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
        $ai->expects($this->once())
            ->method('sendMessage')
            ->willReturn(new AIResponse(content: 'Lectura registrada.'));

        $executor = $this->createMock(ToolExecutor::class);
        $executor->expects($this->once())
            ->method('execute')
            ->with('registrar_lectura', ['equipmentId' => 14], $this->anything())
            ->willReturn(ToolCallResult::success('call_2', 'registrar_lectura', ['readingId' => 99]));

        $registry = new class ($writeTool) implements ToolRegistry {
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

        $actor = ActorContext::fromArray([
            'user_id' => 1,
            'company_id' => 1,
            'super_admin' => false,
            'all_company_branches' => false,
            'roles' => ['tecnico'],
            'permissions' => ['chatbot.usar', 'equipos.ver', 'lecturas.cargar'],
            'branch_ids' => [1],
        ]);

        $result = $handler->execute($actor, new SendMessageCommand(
            conversationId: 1,
            content: '',
            confirmedToolCalls: [
                ['id' => 'call_2', 'name' => 'registrar_lectura', 'arguments' => ['equipmentId' => 14]],
            ],
        ));

        $this->assertEmpty($result->pendingToolCalls);
        $this->assertCount(1, $result->messages);
        $this->assertSame('assistant', $result->messages[0]->role);
        $this->assertSame('Lectura registrada.', $result->messages[0]->content);
    }

    public function testEmptyUserMessageIsNotPersistedOnConfirmation(): void
    {
        $msgRepo = $this->createMock(MessageRepository::class);
        $msgRepo->expects($this->once())
            ->method('append')
            ->with($this->callback(function (Message $m) {
                return $m->role === 'assistant' && $m->content === 'Listo.';
            }))
            ->willReturn(1);
        $msgRepo->method('findForConversation')->willReturn([]);

        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('find')->willReturn(
            Conversation::reconstitute(1, 1, 1, null, new \DateTimeImmutable(), new \DateTimeImmutable())
        );

        $ai = $this->createMock(AIProvider::class);
        $ai->method('sendMessage')->willReturn(new AIResponse(content: 'Listo.'));

        $registry = new class implements ToolRegistry {
            public function all(): array { return []; }
            public function find(string $name): ?ToolDefinition { return null; }
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

        $handler->execute($this->createActor(), new SendMessageCommand(
            conversationId: 1,
            content: '',
            confirmedToolCalls: [],
        ));
    }

    public function testAccessDeniedWhenConversationBelongsToOtherUserInSameCompany(): void
    {
        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('find')->willReturn(
            $this->conversationOwnedBy(convId: 1, userId: 2, companyId: 1)
        );

        $handler = new ProcessMessageHandler(
            messages: $this->createMock(MessageRepository::class),
            toolRegistry: new class implements ToolRegistry {
                public function all(): array { return []; }
                public function find(string $name): ?ToolDefinition { return null; }
            },
            aiProvider: $this->createMock(AIProvider::class),
            toolExecutor: $this->createMock(ToolExecutor::class),
            clock: new class implements ChatClock {
                public function now(): \DateTimeImmutable { return new \DateTimeImmutable(); }
            },
            conversations: $convRepo,
        );

        $this->expectException(\App\Domain\Chatbot\ChatError::class);
        $this->expectExceptionMessage('No tenés acceso a esta conversación.');

        $handler->execute(
            $this->createActor(userId: 1, companyId: 1),
            new SendMessageCommand(conversationId: 1, content: 'Hola')
        );
    }

    public function testAccessDeniedWhenConversationBelongsToAnotherCompany(): void
    {
        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('find')->willReturn(
            $this->conversationOwnedBy(convId: 1, userId: 1, companyId: 9)
        );

        $handler = new ProcessMessageHandler(
            messages: $this->createMock(MessageRepository::class),
            toolRegistry: new class implements ToolRegistry {
                public function all(): array { return []; }
                public function find(string $name): ?ToolDefinition { return null; }
            },
            aiProvider: $this->createMock(AIProvider::class),
            toolExecutor: $this->createMock(ToolExecutor::class),
            clock: new class implements ChatClock {
                public function now(): \DateTimeImmutable { return new \DateTimeImmutable(); }
            },
            conversations: $convRepo,
        );

        $this->expectException(\App\Domain\Chatbot\ChatError::class);

        $handler->execute(
            $this->createActor(userId: 1, companyId: 1),
            new SendMessageCommand(conversationId: 1, content: 'Hola')
        );
    }

    public function testAccessDeniedWhenConversationNotFound(): void
    {
        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('find')->willReturn(null);

        $handler = new ProcessMessageHandler(
            messages: $this->createMock(MessageRepository::class),
            toolRegistry: new class implements ToolRegistry {
                public function all(): array { return []; }
                public function find(string $name): ?ToolDefinition { return null; }
            },
            aiProvider: $this->createMock(AIProvider::class),
            toolExecutor: $this->createMock(ToolExecutor::class),
            clock: new class implements ChatClock {
                public function now(): \DateTimeImmutable { return new \DateTimeImmutable(); }
            },
            conversations: $convRepo,
        );

        $this->expectException(\App\Domain\Chatbot\ChatError::class);

        $handler->execute(
            $this->createActor(),
            new SendMessageCommand(conversationId: 999, content: 'Hola')
        );
    }

    public function testOwnerCanAccessOwnConversation(): void
    {
        $msgRepo = $this->createMock(MessageRepository::class);
        $msgRepo->method('findForConversation')->willReturn([]);
        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('find')->willReturn(
            $this->conversationOwnedBy(convId: 7, userId: 5, companyId: 1)
        );
        $ai = $this->createMock(AIProvider::class);
        $ai->expects($this->once())
            ->method('sendMessage')
            ->willReturn(new AIResponse(content: 'Hola.'));

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

        $result = $handler->execute(
            $this->createActor(userId: 5, companyId: 1),
            new SendMessageCommand(conversationId: 7, content: 'Hola')
        );

        $this->assertSame('Hola.', $result->messages[1]->content);
    }

    public function testSuperAdminCanAccessAnyConversation(): void
    {
        $msgRepo = $this->createMock(MessageRepository::class);
        $msgRepo->method('findForConversation')->willReturn([]);
        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('find')->willReturn(
            $this->conversationOwnedBy(convId: 7, userId: 5, companyId: 12)
        );
        $ai = $this->createMock(AIProvider::class);
        $ai->expects($this->once())
            ->method('sendMessage')
            ->willReturn(new AIResponse(content: 'Hola desde superadmin.'));

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

        $result = $handler->execute(
            $this->createSuperAdmin(),
            new SendMessageCommand(conversationId: 7, content: 'Hola')
        );

        $this->assertSame('Hola desde superadmin.', $result->messages[1]->content);
    }
}
