<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Chatbot;

use App\Application\Chatbot\Command\SendMessageCommand;
use App\Application\Chatbot\Handler\ProcessMessageHandler;
use App\Application\Chatbot\Port\AIProvider;
use App\Application\Chatbot\Port\AIResponse;
use App\Application\Chatbot\Port\ChatClock;
use App\Application\Chatbot\Port\ConversationRepository;
use App\Application\Chatbot\Port\MessageRepository;
use App\Application\Chatbot\Port\ToolExecutor;
use App\Application\Chatbot\Port\ToolRegistry;
use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\Conversation;
use App\Domain\Chatbot\Message;
use App\Domain\Chatbot\ToolCallResult;
use App\Domain\Chatbot\ToolDefinition;
use PHPUnit\Framework\TestCase;

final class ProcessMessageToolChainingTest extends TestCase
{
    public function testReadToolsCanBeChainedUsingPreviousStructuredResult(): void
    {
        $search = ToolDefinition::read('buscar_equipo', 'Busca equipo', ['query' => ['type' => 'string']], 'equipos.ver');
        $plans = ToolDefinition::read('consultar_planes_equipo', 'Consulta planes', ['equipment_id' => ['type' => 'integer']], 'planes.ver');

        $provider = new class implements AIProvider {
            public array $calls = [];
            private int $round = 0;

            public function sendMessage(array $messages, array $tools = []): AIResponse
            {
                $this->calls[] = $messages;
                $this->round++;

                return match ($this->round) {
                    1 => new AIResponse('', [['id' => 'search_1', 'name' => 'buscar_equipo', 'arguments' => ['query' => 'AB123CD']]]),
                    2 => new AIResponse('', [['id' => 'plans_1', 'name' => 'consultar_planes_equipo', 'arguments' => ['equipment_id' => 14]]]),
                    default => new AIResponse('El equipo tiene un plan próximo.'),
                };
            }

            public function sendMessageStreaming(array $messages, array $tools = [], callable $onChunk = null): AIResponse
            {
                return $this->sendMessage($messages, $tools);
            }
        };

        $registry = new class ($search, $plans) implements ToolRegistry {
            public function __construct(private ToolDefinition $search, private ToolDefinition $plans) {}
            public function all(): array { return [$this->search, $this->plans]; }
            public function find(string $name): ?ToolDefinition
            {
                return match ($name) {
                    'buscar_equipo' => $this->search,
                    'consultar_planes_equipo' => $this->plans,
                    default => null,
                };
            }
        };

        $executor = new class implements ToolExecutor {
            public array $executed = [];
            public function execute(string $toolName, array $args, ActorContext $actor): ToolCallResult
            {
                $this->executed[] = [$toolName, $args];
                return match ($toolName) {
                    'buscar_equipo' => ToolCallResult::success('search_1', $toolName, ['items' => [['id' => 14, 'code' => 'CAM-014', 'plate' => 'AB123CD']], 'total' => 1]),
                    'consultar_planes_equipo' => ToolCallResult::success('plans_1', $toolName, ['total' => 1, 'items' => [['service_name' => 'Cambio de aceite', 'state' => 'PROXIMO']]]),
                    default => ToolCallResult::failure('unknown', $toolName, 'unknown'),
                };
            }
        };

        $messages = new class implements MessageRepository {
            public array $items = [];
            public function append(Message $message): int
            {
                $this->items[] = $message;
                return count($this->items);
            }
            public function findForConversation(int $conversationId, int $limit = 50, int $offset = 0): array
            {
                return array_values(array_filter($this->items, static fn (Message $m): bool => $m->conversationId === $conversationId));
            }
            public function countForConversation(int $conversationId): int
            {
                return count(array_filter($this->items, static fn (Message $m): bool => $m->conversationId === $conversationId));
            }
        };

        $conversations = new class implements ConversationRepository {
            public function save(Conversation $conversation): int { return 1; }
            public function find(int $id): ?Conversation
            {
                return Conversation::reconstitute(1, 7, 3, null, new \DateTimeImmutable(), new \DateTimeImmutable());
            }
            public function findByUser(int $usuarioId, int $empresaId, int $limit = 20, int $offset = 0): array { return []; }
            public function findOwned(int $id, int $usuarioId, int $empresaId): ?Conversation { return $this->find($id); }
        };

        $actor = ActorContext::fromArray([
            'user_id' => 7,
            'company_id' => 3,
            'super_admin' => false,
            'all_company_branches' => false,
            'roles' => ['administrador'],
            'permissions' => ['chatbot.usar', 'equipos.ver', 'planes.ver'],
            'branch_ids' => [1],
        ]);

        $handler = new ProcessMessageHandler(
            $messages,
            $registry,
            $provider,
            $executor,
            new class implements ChatClock { public function now(): \DateTimeImmutable { return new \DateTimeImmutable(); } },
            $conversations,
        );

        $result = $handler->execute($actor, new SendMessageCommand(1, '¿Qué mantenimientos tiene AB123CD?'));

        $this->assertSame('El equipo tiene un plan próximo.', $result->messages[1]->content);
        $this->assertSame(['buscar_equipo', ['query' => 'AB123CD']], $executor->executed[0]);
        $this->assertSame(['consultar_planes_equipo', ['equipment_id' => 14]], $executor->executed[1]);
        $this->assertCount(3, $provider->calls);
        $this->assertStringContainsString('"id":14', $provider->calls[1][2]['content']);
        $this->assertStringContainsString('"state":"PROXIMO"', $provider->calls[2][4]['content']);
    }
}
