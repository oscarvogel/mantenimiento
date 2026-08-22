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
use PHPUnit\Framework\TestCase;

final class ProcessMessageDeterministicMeasurementTest extends TestCase
{
    public function testExplicitUnknownPlateNeverFallsBackToPreviousEquipmentOrProvider(): void
    {
        [$handler, $executor, $provider] = $this->makeHandler([
            'buscar_equipo' => ToolCallResult::success('s1', 'buscar_equipo', [
                'items' => [],
                'total' => 0,
                'query' => 'AA0000BB',
                'exact_match' => false,
                'suggestions' => [[
                    'id' => 92,
                    'codigo' => 'CA-EX-01',
                    'patente' => 'AA000BB',
                ]],
            ]),
        ]);

        $result = $handler->execute($this->actor(), new SendMessageCommand(1, 'que km tiene AA0000BB'));

        self::assertSame([
            ['buscar_equipo', ['query' => 'AA0000BB']],
        ], $executor->executed);
        self::assertSame(0, $provider->calls);
        self::assertStringContainsString('AA0000BB', $result->messages[1]->content);
        self::assertStringContainsString('AA000BB', $result->messages[1]->content);
        self::assertStringNotContainsString('205.500', $result->messages[1]->content);
    }

    public function testExplicitKnownPlateUsesExactSearchAndLatestReadingWithoutProvider(): void
    {
        [$handler, $executor, $provider] = $this->makeHandler([
            'buscar_equipo' => ToolCallResult::success('s1', 'buscar_equipo', [
                'items' => [[
                    'id' => 92,
                    'codigo' => 'CA-EX-01',
                    'patente' => 'AA000BB',
                ]],
                'total' => 1,
                'query' => 'AA000BB',
                'exact_match' => true,
                'suggestions' => [],
            ]),
            'consultar_ultima_lectura' => ToolCallResult::success('r1', 'consultar_ultima_lectura', [
                'equipment_id' => 92,
                'has_reading' => true,
                'reading' => [
                    'kilometers' => 205500,
                    'hours' => '3100.0',
                    'recorded_at' => '2026-08-22 12:22:39',
                    'annulled' => false,
                ],
                'links' => [
                    'detail' => 'http://192.168.0.195:8090/mantenimiento/equipos/92',
                ],
            ]),
        ]);

        $result = $handler->execute($this->actor(), new SendMessageCommand(1, 'cuantos km tiene el movil AA000BB'));

        self::assertSame([
            ['buscar_equipo', ['query' => 'AA000BB']],
            ['consultar_ultima_lectura', ['equipment_id' => 92]],
        ], $executor->executed);
        self::assertSame(0, $provider->calls);
        self::assertStringContainsString('205.500 km', $result->messages[1]->content);
        self::assertStringContainsString('3.100 horas', $result->messages[1]->content);
        self::assertStringContainsString('http://192.168.0.195:8090/mantenimiento/equipos/92', $result->messages[1]->content);
    }

    public function testEllipticFollowUpWithNewIdentifierInheritsPreviousMeasurementIntent(): void
    {
        [$handler, $executor, $provider, $messages] = $this->makeHandler([
            'buscar_equipo' => ToolCallResult::success('s2', 'buscar_equipo', [
                'items' => [[
                    'id' => 98,
                    'codigo' => 'DEMO98-CAM01',
                    'patente' => null,
                ]],
                'total' => 1,
                'query' => 'DEMO98-CAM01',
                'exact_match' => true,
                'suggestions' => [],
            ]),
            'consultar_ultima_lectura' => ToolCallResult::success('r2', 'consultar_ultima_lectura', [
                'equipment_id' => 98,
                'has_reading' => true,
                'reading' => [
                    'kilometers' => 123456,
                    'hours' => null,
                    'recorded_at' => '2026-08-22 12:45:00',
                    'annulled' => false,
                ],
                'links' => [
                    'detail' => 'http://192.168.0.195:8090/mantenimiento/equipos/98',
                ],
            ]),
        ]);

        $messages->append(Message::user(1, 'que km tiene AA0000BB'));
        $messages->append(Message::assistant(1, 'No encontré un equipo con código, patente o chasis AA0000BB.'));

        $result = $handler->execute($this->actor(), new SendMessageCommand(1, 'y el DEMO98-CAM01'));

        self::assertSame([
            ['buscar_equipo', ['query' => 'DEMO98-CAM01']],
            ['consultar_ultima_lectura', ['equipment_id' => 98]],
        ], $executor->executed);
        self::assertSame(0, $provider->calls);
        self::assertStringContainsString('DEMO98-CAM01', $result->messages[1]->content);
        self::assertStringContainsString('123.456 km', $result->messages[1]->content);
    }

    public function testEllipticFollowUpWithoutYUsesIdentifierWrittenByUser(): void
    {
        [$handler, $executor, $provider, $messages] = $this->makeHandler([
            'buscar_equipo' => ToolCallResult::success('s3', 'buscar_equipo', [
                'items' => [],
                'total' => 0,
                'query' => 'NEB021',
                'exact_match' => false,
                'suggestions' => [],
            ]),
        ]);

        $messages->append(Message::user(1, 'que km tiene AA0000BB'));
        $messages->append(Message::assistant(1, 'No encontré un equipo con código, patente o chasis AA0000BB.'));

        $result = $handler->execute($this->actor(), new SendMessageCommand(1, 'el NEB021?'));

        self::assertSame([
            ['buscar_equipo', ['query' => 'NEB021']],
        ], $executor->executed);
        self::assertSame(0, $provider->calls);
        self::assertStringContainsString('NEB021', $result->messages[1]->content);
        self::assertStringNotContainsString('CA-EX-01', $result->messages[1]->content);
    }

    /**
     * Cadena real de PRUEBA A: "que km tiene AA0000BB" -> "y el DEMO98-CAM01" -> "el NEB021?"
     * El último mensaje debe heredar intención de medición encontrada en el primer user,
     * Saltándose el segundo user (que también resolvió por herencia pero no contiene la palabra "km").
     */
    public function testEllipticFollowUpSkipsIntermediateEllipsisAndFindsEarlierMeasurementIntent(): void
    {
        [$handler, $executor, $provider, $messages] = $this->makeHandler([
            'buscar_equipo' => ToolCallResult::success('s_neb', 'buscar_equipo', [
                'items' => [],
                'total' => 0,
                'query' => 'NEB021',
                'exact_match' => false,
                'suggestions' => [],
            ]),
            'consultar_ultima_lectura' => ToolCallResult::success('s_lug', 'consultar_ultima_lectura', [
                'equipment_id' => 99,
                'has_reading' => false,
                'reading' => null,
            ]),
        ]);

        $messages->append(Message::user(1, 'que km tiene AA0000BB'));
        $messages->append(Message::assistant(1, 'No encontré un equipo con código, patente o chasis AA0000BB.'));
        $messages->append(Message::user(1, 'y el DEMO98-CAM01'));
        $messages->append(Message::assistant(1, 'No encontré un equipo con código, patente o chasis DEMO98-CAM01.'));

        $result = $handler->execute($this->actor(), new SendMessageCommand(1, 'el NEB021?'));

        self::assertSame([
            ['buscar_equipo', ['query' => 'NEB021']],
        ], $executor->executed);
        self::assertSame(0, $provider->calls);
        self::assertStringContainsString('NEB021', $result->messages[1]->content);
        self::assertStringNotContainsString('CA-EX-01', $result->messages[1]->content);
        self::assertStringNotContainsString('AA000BB', $result->messages[1]->content);
    }

    /**
     * "ese NEB021?", "NEB021?", "y NEB021?" y "y el NEB021?" deben todos resolver NEB021
     * sin provider y sin fallback a CA-EX-01, encadenando la intención de medición del primer user.
     */
    public function testEllipticFollowUpVariantsAllHonorIdentifierAndInheritMeasurementIntent(): void
    {
        $followUps = ['ese NEB021?', 'NEB021?', 'y NEB021?', 'y el NEB021?'];

        foreach ($followUps as $index => $followUp) {
            [$handler, $executor, $provider, $messages] = $this->makeHandler([
                'buscar_equipo' => ToolCallResult::success("s_neb_$index", 'buscar_equipo', [
                    'items' => [],
                    'total' => 0,
                    'query' => 'NEB021',
                    'exact_match' => false,
                    'suggestions' => [],
                ]),
            ]);

            $messages->append(Message::user(1, 'que km tiene AA0000BB'));
            $messages->append(Message::assistant(1, 'No encontré un equipo con código, patente o chasis AA0000BB.'));

            $result = $handler->execute($this->actor(), new SendMessageCommand(1, $followUp));

            self::assertSame(
                [['buscar_equipo', ['query' => 'NEB021']]],
                $executor->executed,
                "Follow up \"$followUp\" debe ejecutar buscar_equipo una sola vez con query NEB021"
            );
            self::assertSame(
                0,
                $provider->calls,
                "Follow up \"$followUp\" no debe invocar al provider LLM"
            );
            self::assertStringContainsString('NEB021', $result->messages[1]->content);
            self::assertStringNotContainsString(
                'CA-EX-01',
                $result->messages[1]->content,
                "Follow up \"$followUp\" no debe mencionar CA-EX-01"
            );
        }
    }

    /**
     * Cambiar de tema explícitamente (de km -> planes) corta la herencia de intención.
     * "qué mantenimientos tiene DEMO98-CAM01" debe dejar fuera de alcance al provider para
     * el seguimiento elíptico siguiente.
     */
    public function testEllipticFollowUpDoesNotInheritAcrossExplicitTopicChange(): void
    {
        $provider = new class implements AIProvider {
            public int $calls = 0;
            public array $receivedMessages = [];
            public function sendMessage(array $messages, array $tools = []): AIResponse
            {
                $this->calls++;
                $this->receivedMessages = $messages;

                return new AIResponse('No reconocido como lookup de medición.', [], null);
            }
            public function sendMessageStreaming(array $messages, array $tools = [], callable $onChunk = null): AIResponse
            {
                return $this->sendMessage($messages, $tools);
            }
        };
        $executor = new class implements ToolExecutor {
            public array $executed = [];
            public function execute(string $toolName, array $args, ActorContext $actor): ToolCallResult
            {
                $this->executed[] = [$toolName, $args];
                return ToolCallResult::failure('missing', $toolName, 'no debe ejecutar tools');
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
        $registry = new class implements ToolRegistry {
            public function all(): array { return []; }
            public function find(string $name): ?\App\Domain\Chatbot\ToolDefinition { return null; }
        };

        $handler = new ProcessMessageHandler(
            $messages,
            $registry,
            $provider,
            $executor,
            new class implements ChatClock { public function now(): \DateTimeImmutable { return new \DateTimeImmutable(); } },
            $conversations,
        );

        $messages->append(Message::user(1, 'que km tiene AA000BB'));
        $messages->append(Message::assistant(1, 'No encontré un equipo con código, patente o chasis AA000BB.'));
        $messages->append(Message::user(1, 'qué mantenimientos tiene DEMO98-CAM01'));
        $messages->append(Message::assistant(1, 'No encontré un equipo con código, patente o chasis DEMO98-CAM01.'));

        $handler->execute($this->actor(), new SendMessageCommand(1, 'el NEB021?'));

        self::assertSame([], $executor->executed, 'No debe ejecutar buscar_equipo: hubo cambio de tema a planes.');
        self::assertGreaterThanOrEqual(1, $provider->calls, 'Después de cambio de tema, debe delegar al provider.');
    }

    private function actor(): ActorContext
    {
        return ActorContext::fromArray([
            'user_id' => 7,
            'company_id' => 3,
            'super_admin' => false,
            'all_company_branches' => false,
            'roles' => ['administrador'],
            'permissions' => ['chatbot.usar', 'equipos.ver', 'lecturas.ver'],
            'branch_ids' => [1],
        ]);
    }

    private function makeHandler(array $results): array
    {
        $provider = new class implements AIProvider {
            public int $calls = 0;
            public function sendMessage(array $messages, array $tools = []): AIResponse
            {
                $this->calls++;
                throw new \RuntimeException('El provider no debe invocarse para lookup explícito de mediciones.');
            }
            public function sendMessageStreaming(array $messages, array $tools = [], callable $onChunk = null): AIResponse
            {
                return $this->sendMessage($messages, $tools);
            }
        };

        $executor = new class ($results) implements ToolExecutor {
            public array $executed = [];
            public function __construct(private array $results) {}
            public function execute(string $toolName, array $args, ActorContext $actor): ToolCallResult
            {
                $this->executed[] = [$toolName, $args];
                return $this->results[$toolName]
                    ?? ToolCallResult::failure('missing', $toolName, 'resultado no configurado');
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

        $registry = new class implements ToolRegistry {
            public function all(): array { return []; }
            public function find(string $name): ?\App\Domain\Chatbot\ToolDefinition { return null; }
        };

        return [
            new ProcessMessageHandler(
                $messages,
                $registry,
                $provider,
                $executor,
                new class implements ChatClock { public function now(): \DateTimeImmutable { return new \DateTimeImmutable(); } },
                $conversations,
            ),
            $executor,
            $provider,
            $messages,
        ];
    }
}
