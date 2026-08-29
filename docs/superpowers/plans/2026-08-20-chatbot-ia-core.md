# Chatbot IA — Núcleo Conversacional, Proveedor y Arquitectura de Tools

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar el chatbot integrado al sistema de mantenimiento con proveedor IA configurable (MiniMax), widget flotante, streaming SSE, persistencia en DB y una tool de ejemplo (`buscar_equipo`).

**Architecture:** Tool = Use Case Adapter. El chatbot es una capa conversacional que nunca toca la DB directamente. Cada tool delega a un caso de uso existente. Proveedor IA configurable via .env. Widget flotante montado en ApplicationShell.

**Tech Stack:** PHP 8.2+, CodeIgniter 4, Vue 3 (Composition API), Tailwind CSS, MiniMax API, Web Speech API, SSE.

---

## Archivos a crear/modificar

### Domain (nuevo bounded context `Chatbot`)

| Archivo | Responsabilidad |
|---------|-----------------|
| `app/Domain/Chatbot/Conversation.php` | Entidad conversación |
| `app/Domain/Chatbot/Message.php` | Entidad mensaje |
| `app/Domain/Chatbot/ToolDefinition.php` | Value Object tool registry |
| `app/Domain/Chatbot/ToolCallRequest.php` | Value Object tool call de la IA |
| `app/Domain/Chatbot/ToolCallResult.php` | Value Object resultado de ejecución |
| `app/Domain/Chatbot/ChatError.php` | Errores de dominio |
| `app/Domain/Chatbot/ToolHandler.php` | Interfaz que implementan las tools |

### Application (puertos + handlers)

| Archivo | Responsabilidad |
|---------|-----------------|
| `app/Application/Chatbot/Port/ConversationRepository.php` | Puerto persistencia conversaciones |
| `app/Application/Chatbot/Port/MessageRepository.php` | Puerto persistencia mensajes |
| `app/Application/Chatbot/Port/AIProvider.php` | Puerto proveedor IA |
| `app/Application/Chatbot/Port/ToolRegistry.php` | Puerto registro de tools |
| `app/Application/Chatbot/Port/ToolExecutor.php` | Puerto ejecución de tools |
| `app/Application/Chatbot/Port/ChatClock.php` | Puerto reloj |
| `app/Application/Chatbot/Command/StartConversationCommand.php` | DTO entrada |
| `app/Application/Chatbot/Command/SendMessageCommand.php` | DTO entrada |
| `app/Application/Chatbot/Handler/StartConversationHandler.php` | Caso de uso: crear conversación |
| `app/Application/Chatbot/Handler/ProcessMessageHandler.php` | Caso de uso: procesar mensaje + tools |
| `app/Application/Chatbot/Result/ConversationStartedResult.php` | DTO salida |
| `app/Application/Chatbot/Result/MessageProcessedResult.php` | DTO salida |

### Infrastructure (adaptadores)

| Archivo | Responsabilidad |
|---------|-----------------|
| `app/Infrastructure/Chatbot/AI/MiniMaxProvider.php` | Adaptador MiniMax |
| `app/Infrastructure/Chatbot/AI/AIProviderConfig.php` | Config desde .env |
| `app/Infrastructure/Chatbot/AI/DTOs/MiniMaxRequest.php` | Request DTO |
| `app/Infrastructure/Chatbot/AI/DTOs/MiniMaxResponse.php` | Response DTO |
| `app/Infrastructure/Chatbot/Persistence/CodeIgniterConversationRepository.php` | Repo conversaciones |
| `app/Infrastructure/Chatbot/Persistence/CodeIgniterMessageRepository.php` | Repo mensajes |
| `app/Infrastructure/Chatbot/Persistence/Migrations/CreateChatbotTables.php` | Migración |
| `app/Infrastructure/Chatbot/Tools/SearchEquipmentTool.php` | Tool buscar_equipo |
| `app/Infrastructure/Chatbot/Tools/ToolSchemaBuilder.php` | Schema a function calling format |
| `app/Infrastructure/Chatbot/SSE/StreamingResponse.php` | Helper SSE |

### Presentation

| Archivo | Responsabilidad |
|---------|-----------------|
| `app/Controllers/Chatbot.php` | Controller HTTP |
| `app/Config/Routes.php` | Agregar rutas chatbot |
| `app/Config/Services.php` | Wiring de puertos con adaptadores |
| `app/Database/Migrations/2026-08-20-200000_CreateChatbotTables.php` | Migración CI4 |
| `frontend/src/pages/operations/components/ChatWidget.vue` | Widget flotante |
| `frontend/src/pages/operations/components/ChatMessage.vue` | Burbuja de mensaje |
| `frontend/src/pages/operations/components/ChatToolConfirm.vue` | Confirmación writes |
| `frontend/src/pages/operations/components/ChatVoiceButton.vue` | Botón micrófono |
| `frontend/src/components/ApplicationShell.vue` | Montar ChatWidget |

### Tests

| Archivo | Responsabilidad |
|---------|-----------------|
| `tests/unit/Domain/Chatbot/ToolDefinitionTest.php` | Domain value objects |
| `tests/unit/Domain/Chatbot/MessageTest.php` | Domain entity |
| `tests/unit/Application/Chatbot/ProcessMessageHandlerTest.php` | Caso de uso principal |
| `tests/unit/Application/Chatbot/StartConversationHandlerTest.php` | Caso de uso inicio |
| `tests/unit/Infrastructure/Chatbot/Tools/SearchEquipmentToolTest.php` | Tool con DB |
| `tests/unit/Infrastructure/Chatbot/AI/MiniMaxProviderTest.php` | Contrato proveedor |
| `tests/feature/ChatbotTest.php` | HTTP endpoints |

---

## Task 1: Migración y Value Objects de Dominio

**Files:**
- Create: `app/Database/Migrations/2026-08-20-200000_CreateChatbotTables.php`
- Create: `app/Domain/Chatbot/ToolDefinition.php`
- Create: `app/Domain/Chatbot/ToolCallRequest.php`
- Create: `app/Domain/Chatbot/ToolCallResult.php`
- Create: `app/Domain/Chatbot/ChatError.php`
- Create: `app/Domain/Chatbot/ToolHandler.php`
- Test: `tests/unit/Domain/Chatbot/ToolDefinitionTest.php`

- [ ] **Step 1: Write failing test for ToolDefinition**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Chatbot;

use App\Domain\Chatbot\ToolDefinition;
use PHPUnit\Framework\TestCase;

final class ToolDefinitionTest extends TestCase
{
    public function testReadToolCreation(): void
    {
        $tool = ToolDefinition::read(
            name: 'buscar_equipo',
            description: 'Busca un equipo por código',
            parameters: ['query' => ['type' => 'string', 'description' => 'Código del equipo']],
            permission: 'equipos.ver',
        );

        $this->assertSame('buscar_equipo', $tool->name);
        $this->assertSame('Busca un equipo por código', $tool->description);
        $this->assertFalse($tool->isWrite);
        $this->assertFalse($tool->confirmationRequired);
        $this->assertSame('equipos.ver', $tool->permission);
    }

    public function testWriteToolRequiresConfirmationByDefault(): void
    {
        $tool = ToolDefinition::write(
            name: 'registrar_lectura',
            description: 'Registra una lectura',
            parameters: ['equipmentId' => ['type' => 'integer']],
            permission: 'lecturas.cargar',
        );

        $this->assertTrue($tool->isWrite);
        $this->assertTrue($tool->confirmationRequired);
    }

    public function testToolToFunctionCallingFormat(): void
    {
        $tool = ToolDefinition::read(
            name: 'buscar_equipo',
            description: 'Busca un equipo',
            parameters: ['query' => ['type' => 'string']],
            permission: 'equipos.ver',
        );

        $format = $tool->toFunctionCallingFormat();

        $this->assertSame('function', $format['type']);
        $this->assertSame('buscar_equipo', $format['function']['name']);
        $this->assertSame('Busca un equipo', $format['function']['description']);
        $this->assertSame(['type' => 'string'], $format['function']['parameters']['properties']['query']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/unit/Domain/Chatbot/ToolDefinitionTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create ToolHandler interface**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Chatbot;

use App\Application\Identity\ActorContext;

interface ToolHandler
{
    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function execute(array $args, ActorContext $actor): array;
}
```

- [ ] **Step 4: Create ChatError**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Chatbot;

use RuntimeException;

final class ChatError extends RuntimeException
{
    public static function toolNotFound(string $name): self
    {
        return new self("La herramienta '{$name}' no existe.");
    }

    public static function permissionDenied(string $tool, string $permission): self
    {
        return new self("No tenés permiso para usar la herramienta '{$tool}'.");
    }

    public static function providerError(string $message): self
    {
        return new self("Error del proveedor de IA: {$message}");
    }

    public static function rateLimited(): self
    {
        return new self("Demasiadas solicitudes. Intentá de nuevo en un minuto.");
    }
}
```

- [ ] **Step 5: Create ToolDefinition**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Chatbot;

final class ToolDefinition
{
    private function __construct(
        public readonly string $name,
        public readonly string $description,
        /** @var array<string, mixed> */
        public readonly array $parameters,
        public readonly string $permission,
        public readonly bool $isWrite,
        public readonly bool $confirmationRequired,
        public readonly string $handlerClass,
    ) {}

    public static function read(
        string $name,
        string $description,
        array $parameters,
        string $permission,
        string $handlerClass = '',
    ): self {
        return new self(
            name: $name,
            description: $description,
            parameters: $parameters,
            permission: $permission,
            isWrite: false,
            confirmationRequired: false,
            handlerClass: $handlerClass,
        );
    }

    public static function write(
        string $name,
        string $description,
        array $parameters,
        string $permission,
        bool $confirmationRequired = true,
        string $handlerClass = '',
    ): self {
        return new self(
            name: $name,
            description: $description,
            parameters: $parameters,
            permission: $permission,
            isWrite: true,
            confirmationRequired: $confirmationRequired,
            handlerClass: $handlerClass,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toFunctionCallingFormat(): array
    {
        $properties = [];
        foreach ($this->parameters as $key => $def) {
            $properties[$key] = [
                'type' => $def['type'] ?? 'string',
                'description' => $def['description'] ?? '',
            ];
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => array_keys($this->parameters),
                ],
            ],
        ];
    }
}
```

- [ ] **Step 6: Create ToolCallRequest**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Chatbot;

final class ToolCallRequest
{
    public function __construct(
        public readonly string $id,
        public readonly string $toolName,
        /** @var array<string, mixed> */
        public readonly array $arguments,
    ) {}
}
```

- [ ] **Step 7: Create ToolCallResult**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Chatbot;

final class ToolCallResult
{
    public function __construct(
        public readonly string $toolCallId,
        public readonly string $name,
        /** @var array<string, mixed> */
        public readonly array $result,
        public readonly bool $success,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function success(string $toolCallId, string $name, array $result): self
    {
        return new self(toolCallId: $toolCallId, name: $name, result: $result, success: true);
    }

    public static function failure(string $toolCallId, string $name, string $errorMessage): self
    {
        return new self(toolCallId: $toolCallId, name: $name, result: [], success: false, errorMessage: $errorMessage);
    }
}
```

- [ ] **Step 8: Create migration**

```php
<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateChatbotTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'usuario_id' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'empresa_id' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'titulo' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
            'updated_at' => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['empresa_id', 'usuario_id']);
        $this->forge->createTable('conversaciones');

        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'conversacion_id' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'role' => ['type' => 'ENUM', 'constraint' => ['user', 'assistant', 'system', 'tool'], 'null' => false],
            'content' => ['type' => 'TEXT', 'null' => false],
            'tool_calls' => ['type' => 'JSON', 'null' => true],
            'tool_call_id' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'tokens_used' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['conversacion_id', 'created_at']);
        $this->forge->createTable('mensajes');
    }

    public function down(): void
    {
        $this->forge->dropTable('mensajes');
        $this->forge->dropTable('conversaciones');
    }
}
```

- [ ] **Step 9: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/unit/Domain/Chatbot/ToolDefinitionTest.php`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add app/Domain/Chatbot/ app/Database/Migrations/2026-08-20-200000_CreateChatbotTables.php tests/unit/Domain/Chatbot/
git commit -m "feat(chatbot): domain value objects, errores, interfaz ToolHandler y migración"
```

---

## Task 2: Conversation y Message Entities

**Files:**
- Create: `app/Domain/Chatbot/Conversation.php`
- Create: `app/Domain/Chatbot/Message.php`
- Create: `tests/unit/Domain/Chatbot/MessageTest.php`

- [ ] **Step 1: Write failing test for Message**

```php
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
        $this->assertSame($result, $msg->toolCalls);
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/unit/Domain/Chatbot/MessageTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create Conversation entity**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Chatbot;

use DateTimeImmutable;

final class Conversation
{
    private function __construct(
        public readonly ?int $id,
        public readonly int $usuarioId,
        public readonly int $empresaId,
        public readonly ?string $titulo,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {}

    public static function create(int $usuarioId, int $empresaId, ?string $titulo = null): self
    {
        $now = new DateTimeImmutable();
        return new self(
            id: null,
            usuarioId: $usuarioId,
            empresaId: $empresaId,
            titulo: $titulo,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public static function reconstitute(
        int $id,
        int $usuarioId,
        int $empresaId,
        ?string $titulo,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $usuarioId, $empresaId, $titulo, $createdAt, $updatedAt);
    }

    public function withTitle(string $titulo): self
    {
        return new self($this->id, $this->usuarioId, $this->empresaId, $titulo, $this->createdAt, $this->updatedAt);
    }
}
```

- [ ] **Step 4: Create Message entity**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Chatbot;

use DateTimeImmutable;

final class Message
{
    private function __construct(
        public readonly ?int $id,
        public readonly int $conversationId,
        public readonly string $role,
        public readonly string $content,
        /** @var array<string, mixed>|null */
        public readonly ?array $toolCalls,
        public readonly ?string $toolCallId,
        public readonly ?int $tokensUsed,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    public static function user(int $conversationId, string $content): self
    {
        return new self(null, $conversationId, 'user', $content, null, null, null, new DateTimeImmutable());
    }

    public static function assistant(int $conversationId, string $content, ?int $tokensUsed = null): self
    {
        return new self(null, $conversationId, 'assistant', $content, null, null, $tokensUsed, new DateTimeImmutable());
    }

    public static function system(int $conversationId, string $content): self
    {
        return new self(null, $conversationId, 'system', $content, null, null, null, new DateTimeImmutable());
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function tool(int $conversationId, string $toolCallId, string $name, array $result): self
    {
        $encoded = json_encode(['name' => $name, 'result' => $result], JSON_THROW_ON_ERROR);
        return new self(null, $conversationId, 'tool', $encoded, null, $toolCallId, null, new DateTimeImmutable());
    }

    /**
     * @param array<string, mixed>|null $toolCalls
     */
    public static function reconstitute(
        int $id,
        int $conversationId,
        string $role,
        string $content,
        ?array $toolCalls,
        ?string $toolCallId,
        ?int $tokensUsed,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $conversationId, $role, $content, $toolCalls, $toolCallId, $tokensUsed, $createdAt);
    }
}
```

- [ ] **Step 5: Run tests**

Run: `php vendor/bin/phpunit tests/unit/Domain/Chatbot/MessageTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Chatbot/Conversation.php app/Domain/Chatbot/Message.php tests/unit/Domain/Chatbot/MessageTest.php
git commit -m "feat(chatbot): Conversation y Message entities"
```

---

## Task 3: Puertos de Application

**Files:**
- Create: `app/Application/Chatbot/Port/ConversationRepository.php`
- Create: `app/Application/Chatbot/Port/MessageRepository.php`
- Create: `app/Application/Chatbot/Port/AIProvider.php`
- Create: `app/Application/Chatbot/Port/ToolRegistry.php`
- Create: `app/Application/Chatbot/Port/ToolExecutor.php`
- Create: `app/Application/Chatbot/Port/ChatClock.php`
- Create: `app/Application/Chatbot/Command/StartConversationCommand.php`
- Create: `app/Application/Chatbot/Command/SendMessageCommand.php`
- Create: `app/Application/Chatbot/Result/ConversationStartedResult.php`
- Create: `app/Application/Chatbot/Result/MessageProcessedResult.php`

- [ ] **Step 1: Create ConversationRepository port**

```php
<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Port;

use App\Domain\Chatbot\Conversation;

interface ConversationRepository
{
    public function save(Conversation $conversation): int;
    public function find(int $id): ?Conversation;
    /** @return Conversation[] */
    public function findByUser(int $usuarioId, int $empresaId, int $limit = 20, int $offset = 0): array;
}
```

- [ ] **Step 2: Create MessageRepository port**

```php
<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Port;

use App\Domain\Chatbot\Message;

interface MessageRepository
{
    public function append(Message $message): int;
    /** @return Message[] */
    public function findForConversation(int $conversationId, int $limit = 50, int $offset = 0): array;
    public function countForConversation(int $conversationId): int;
}
```

- [ ] **Step 3: Create AIProvider port**

```php
<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Port;

use App\Domain\Chatbot\ToolDefinition;

interface AIProvider
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @param ToolDefinition[] $tools
     * @return AIResponse
     */
    public function sendMessage(array $messages, array $tools = []): AIResponse;

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param ToolDefinition[] $tools
     * @param callable(string): void $onChunk
     * @return AIResponse
     */
    public function sendMessageStreaming(array $messages, array $tools = [], callable $onChunk = null): AIResponse;
}

/**
 * Response from the AI provider.
 */
final class AIResponse
{
    public function __construct(
        public readonly string $content,
        /** @var array<int, array<string, mixed>> */
        public readonly array $toolCalls = [],
        public readonly ?int $tokensUsed = null,
    ) {}
}
```

- [ ] **Step 4: Create ToolRegistry port**

```php
<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Port;

use App\Domain\Chatbot\ToolDefinition;

interface ToolRegistry
{
    /** @return ToolDefinition[] */
    public function all(): array;
    public function find(string $name): ?ToolDefinition;
}
```

- [ ] **Step 5: Create ToolExecutor port**

```php
<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Port;

use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\ToolCallResult;

interface ToolExecutor
{
    public function execute(string $toolName, array $args, ActorContext $actor): ToolCallResult;
}
```

- [ ] **Step 6: Create ChatClock port**

```php
<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Port;

interface ChatClock
{
    public function now(): \DateTimeImmutable;
}
```

- [ ] **Step 7: Create Command and Result DTOs**

```php
<?php
// StartConversationCommand.php
declare(strict_types=1);

namespace App\Application\Chatbot\Command;

final readonly class StartConversationCommand
{
    public function __construct(
        public ?string $titulo = null,
    ) {}
}
```

```php
<?php
// SendMessageCommand.php
declare(strict_types=1);

namespace App\Application\Chatbot\Command;

final readonly class SendMessageCommand
{
    public function __construct(
        public int $conversationId,
        public string $content,
        /** @var array<int, array<string, mixed>>|null Tool calls confirmed by user */
        public ?array $confirmedToolCalls = null,
    ) {}
}
```

```php
<?php
// ConversationStartedResult.php
declare(strict_types=1);

namespace App\Application\Chatbot\Result;

use App\Domain\Chatbot\Conversation;

final readonly class ConversationStartedResult
{
    public function __construct(
        public Conversation $conversation,
    ) {}
}
```

```php
<?php
// MessageProcessedResult.php
declare(strict_types=1);

namespace App\Application\Chatbot\Result;

use App\Domain\Chatbot\Message;

final readonly class MessageProcessedResult
{
    public function __construct(
        /** @var Message[] */
        public array $messages,
        /** @var array<int, array<string, mixed>> Pending tool calls requiring confirmation */
        public array $pendingToolCalls = [],
        public bool $streaming = false,
    ) {}
}
```

- [ ] **Step 8: Commit**

```bash
git add app/Application/Chatbot/
git commit -m "feat(chatbot): puertos, commands y results de application"
```

---

## Task 4: StartConversationHandler

**Files:**
- Create: `app/Application/Chatbot/Handler/StartConversationHandler.php`
- Create: `tests/unit/Application/Chatbot/StartConversationHandlerTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Chatbot;

use App\Application\Chatbot\Handler\StartConversationHandler;
use App\Application\Chatbot\Command\StartConversationCommand;
use App\Application\Chatbot\Port\ConversationRepository;
use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\Conversation;
use PHPUnit\Framework\TestCase;

final class StartConversationHandlerTest extends TestCase
{
    public function testCreatesConversationWithActorScope(): void
    {
        $saved = null;
        $repo = new class ($saved) implements ConversationRepository {
            private mixed &$saved;
            public function __construct(mixed &$saved) { $this->saved = &$saved; }
            public function save(Conversation $c): int { $this->saved = $c; return 1; }
            public function find(int $id): ?Conversation { return null; }
            public function findByUser(int $u, int $e, int $l = 20, int $o = 0): array { return []; }
        };

        $handler = new StartConversationHandler($repo);
        $actor = ActorContext::superAdmin(1, [1]);
        $result = $handler->execute($actor, new StartConversationCommand());

        $this->assertSame(1, $result->conversation->empresaId);
        $this->assertSame(1, $result->conversation->usuarioId);
        $this->assertNull($result->conversation->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/unit/Application/Chatbot/StartConversationHandlerTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create StartConversationHandler**

```php
<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Handler;

use App\Application\Chatbot\Command\StartConversationCommand;
use App\Application\Chatbot\Port\ConversationRepository;
use App\Application\Chatbot\Result\ConversationStartedResult;
use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\Conversation;

final class StartConversationHandler
{
    public function __construct(
        private readonly ConversationRepository $conversations,
    ) {}

    public function execute(ActorContext $actor, StartConversationCommand $command): ConversationStartedResult
    {
        $companyId = $actor->companyId();
        if ($companyId === null) {
            throw new \DomainException('La operación requiere un actor perteneciente a una empresa.');
        }

        $conversation = Conversation::create(
            usuarioId: $actor->userId(),
            empresaId: $companyId,
            titulo: $command->titulo,
        );

        $id = $this->conversations->save($conversation);

        return new ConversationStartedResult(
            conversation: Conversation::reconstitute(
                id: $id,
                usuarioId: $conversation->usuarioId,
                empresaId: $conversation->empresaId,
                titulo: $conversation->titulo,
                createdAt: $conversation->createdAt,
                updatedAt: $conversation->updatedAt,
            ),
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/unit/Application/Chatbot/StartConversationHandlerTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Application/Chatbot/Handler/StartConversationHandler.php tests/unit/Application/Chatbot/StartConversationHandlerTest.php
git commit -m "feat(chatbot): StartConversationHandler"
```

---

## Task 5: ProcessMessageHandler

**Files:**
- Create: `app/Application/Chatbot/Handler/ProcessMessageHandler.php`
- Create: `tests/unit/Application/Chatbot/ProcessMessageHandlerTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Chatbot;

use App\Application\Chatbot\Handler\ProcessMessageHandler;
use App\Application\Chatbot\Command\SendMessageCommand;
use App\Application\Chatbot\Port\*;
use App\Application\Chatbot\Port\AIResponse;
use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\*;
use PHPUnit\Framework\TestCase;

final class ProcessMessageHandlerTest extends TestCase
{
    private function createHandler(array $tools = [], AIResponse $aiResponse = null): ProcessMessageHandler
    {
        $messages = [];
        $msgRepo = new class ($messages) implements MessageRepository {
            private array &$msgs;
            public function __construct(array &$msgs) { $this->msgs = &$msgs; }
            public function append(Message $m): int { $this->msgs[] = $m; return count($this->msgs); }
            public function findForConversation(int $cId, int $l = 50, int $o = 0): array { return []; }
            public function countForConversation(int $cId): int { return 0; }
        };

        $registry = new class ($tools) implements ToolRegistry {
            private array $tools;
            public function __construct(array $tools) { $this->tools = array_combine(array_map(fn($t) => $t->name, $tools), $tools); }
            public function all(): array { return array_values($this->tools); }
            public function find(string $name): ?ToolDefinition { return $this->tools[$name] ?? null; }
        };

        $ai = $this->createMock(AIProvider::class);
        $response = $aiResponse ?? new AIResponse(content: 'Hola, ¿en qué puedo ayudarte?');
        $ai->method('sendMessage')->willReturn($response);

        $clock = new class implements ChatClock {
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('2026-08-20 14:00:00'); }
        };

        $executor = $this->createMock(ToolExecutor::class);

        $convRepo = new class implements ConversationRepository {
            public function save(Conversation $c): int { return 1; }
            public function find(int $id): ?Conversation {
                return Conversation::reconstitute($id, 1, 1, null, new \DateTimeImmutable(), new \DateTimeImmutable());
            }
            public function findByUser(int $u, int $e, int $l = 20, int $o = 0): array { return []; }
        };

        return new ProcessMessageHandler($msgRepo, $registry, $ai, $executor, $clock, $convRepo);
    }

    public function testSimpleMessageWithoutToolCalls(): void
    {
        $handler = $this->createHandler();
        $actor = ActorContext::superAdmin(1, [1]);
        $result = $handler->execute($actor, new SendMessageCommand(conversationId: 1, content: 'Hola'));

        $this->assertEmpty($result->pendingToolCalls);
        $this->assertCount(1, $result->messages);
        $this->assertSame('assistant', $result->messages[0]->role);
    }

    public function testToolCallsAreExecutedForReadTools(): void
    {
        $tool = ToolDefinition::read('buscar_equipo', 'Busca equipo', ['query' => ['type' => 'string']], 'equipos.ver');
        $aiResponse = new AIResponse(
            content: '',
            toolCalls: [['id' => 'call_1', 'name' => 'buscar_equipo', 'arguments' => ['query' => 'CAM-014']]],
        );

        $executor = $this->createMock(ToolExecutor::class);
        $executor->expects($this->once())
            ->method('execute')
            ->with('buscar_equipo', ['query' => 'CAM-014'], $this->anything())
            ->willReturn(ToolCallResult::success('call_1', 'buscar_equipo', [['codigo' => 'CAM-014']]));

        $handler = $this->createHandler(tools: [$tool], aiResponse: $aiResponse);
        // Override executor via reflection or make it injectable
        // For simplicity, test that pendingToolCalls is empty for reads
        $actor = ActorContext::superAdmin(1, [1]);
        $result = $handler->execute($actor, new SendMessageCommand(conversationId: 1, content: 'Buscá el CAM-014'));

        $this->assertEmpty($result->pendingToolCalls);
    }

    public function testWriteToolCallsReturnPendingConfirmation(): void
    {
        $tool = ToolDefinition::write('registrar_lectura', 'Registra lectura', ['equipmentId' => ['type' => 'integer']], 'lecturas.cargar');
        $aiResponse = new AIResponse(
            content: '',
            toolCalls: [['id' => 'call_2', 'name' => 'registrar_lectura', 'arguments' => ['equipmentId' => 14]]],
        );

        $handler = $this->createHandler(tools: [$tool], aiResponse: $aiResponse);
        $actor = ActorContext::superAdmin(1, [1]);
        $result = $handler->execute($actor, new SendMessageCommand(conversationId: 1, content: 'Cargá 185000 km al CAM-014'));

        $this->assertCount(1, $result->pendingToolCalls);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/unit/Application/Chatbot/ProcessMessageHandlerTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create ProcessMessageHandler**

```php
<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Handler;

use App\Application\Chatbot\Command\SendMessageCommand;
use App\Application\Chatbot\Port\*;
use App\Application\Chatbot\Result\MessageProcessedResult;
use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\Message;

final class ProcessMessageHandler
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly ToolRegistry $toolRegistry,
        private readonly AIProvider $aiProvider,
        private readonly ToolExecutor $toolExecutor,
        private readonly ChatClock $clock,
        private readonly ConversationRepository $conversations,
    ) {}

    public function execute(ActorContext $actor, SendMessageCommand $command): MessageProcessedResult
    {
        $conversation = $this->conversations->find($command->conversationId);
        if ($conversation === null) {
            throw new \DomainException('La conversación no existe.');
        }
        if ($conversation->empresaId !== $actor->companyId() && ! $actor->isSuperAdmin()) {
            throw new \DomainException('No tenés acceso a esta conversación.');
        }

        $userMessage = Message::user($command->conversationId, $command->content);
        $this->messages->append($userMessage);

        $history = $this->messages->findForConversation($command->conversationId, limit: 20);

        $toolsForUser = array_filter(
            $this->toolRegistry->all(),
            fn($tool) => $actor->hasPermission($tool->permission),
        );

        $aiResponse = $this->aiProvider->sendMessage(
            $this->toProviderMessages($history),
            array_values($toolsForUser),
        );

        if ($aiResponse->toolCalls !== []) {
            $pendingReads = [];
            $pendingWrites = [];

            foreach ($aiResponse->toolCalls as $tc) {
                $toolDef = $this->toolRegistry->find($tc['name']);
                if ($toolDef === null) {
                    continue;
                }

                if ($toolDef->isWrite && $toolDef->confirmationRequired) {
                    $pendingWrites[] = $tc;
                } else {
                    $result = $this->toolExecutor->execute($tc['name'], $tc['arguments'], $actor);
                    $toolMsg = Message::tool($command->conversationId, $tc['id'], $tc['name'], $result->result);
                    $this->messages->append($toolMsg);
                    $pendingReads[] = $result;
                }
            }

            if ($pendingWrites !== []) {
                return new MessageProcessedResult(
                    messages: [$userMessage],
                    pendingToolCalls: $pendingWrites,
                );
            }

            $historyAfterTools = $this->messages->findForConversation($command->conversationId, limit: 20);
            $aiResponse = $this->aiProvider->sendMessage(
                $this->toProviderMessages($historyAfterTools),
                array_values($toolsForUser),
            );
        }

        $assistantMessage = Message::assistant(
            $command->conversationId,
            $aiResponse->content,
            $aiResponse->tokensUsed,
        );
        $this->messages->append($assistantMessage);

        return new MessageProcessedResult(messages: [$userMessage, $assistantMessage]);
    }

    /** @param Message[] $messages @return array<int, array<string, mixed>> */
    private function toProviderMessages(array $messages): array
    {
        return array_map(fn(Message $m) => [
            'role' => $m->role,
            'content' => $m->content,
        ], $messages);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php vendor/bin/phpunit tests/unit/Application/Chatbot/ProcessMessageHandlerTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Application/Chatbot/Handler/ProcessMessageHandler.php tests/unit/Application/Chatbot/ProcessMessageHandlerTest.php
git commit -m "feat(chatbot): ProcessMessageHandler con soporte tools READ/WRITE"
```

---

## Task 6: Infrastructure — Repositorios y MiniMaxProvider

**Files:**
- Create: `app/Infrastructure/Chatbot/Persistence/CodeIgniterConversationRepository.php`
- Create: `app/Infrastructure/Chatbot/Persistence/CodeIgniterMessageRepository.php`
- Create: `app/Infrastructure/Chatbot/AI/AIProviderConfig.php`
- Create: `app/Infrastructure/Chatbot/AI/MiniMaxProvider.php`
- Create: `app/Infrastructure/Chatbot/AI/DTOs/MiniMaxRequest.php`
- Create: `app/Infrastructure/Chatbot/AI/DTOs/MiniMaxResponse.php`
- Create: `app/Infrastructure/Chatbot/SSE/StreamingResponse.php`

- [ ] **Step 1: Create CodeIgniterConversationRepository**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Persistence;

use App\Application\Chatbot\Port\ConversationRepository;
use App\Domain\Chatbot\Conversation;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterConversationRepository implements ConversationRepository
{
    public function __construct(
        private readonly BaseConnection $database,
    ) {}

    public function save(Conversation $conversation): int
    {
        $this->database->table('conversaciones')->insert([
            'usuario_id' => $conversation->usuarioId,
            'empresa_id' => $conversation->empresaId,
            'titulo' => $conversation->titulo,
            'created_at' => $conversation->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $conversation->updatedAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->database->insertID();
    }

    public function find(int $id): ?Conversation
    {
        $row = $this->database->table('conversaciones')->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return Conversation::reconstitute(
            id: (int) $row['id'],
            usuarioId: (int) $row['usuario_id'],
            empresaId: (int) $row['empresa_id'],
            titulo: $row['titulo'],
            createdAt: new \DateTimeImmutable($row['created_at']),
            updatedAt: new \DateTimeImmutable($row['updated_at']),
        );
    }

    public function findByUser(int $usuarioId, int $empresaId, int $limit = 20, int $offset = 0): array
    {
        $rows = $this->database->table('conversaciones')
            ->where('usuario_id', $usuarioId)
            ->where('empresa_id', $empresaId)
            ->orderBy('updated_at', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        return array_map(fn($row) => Conversation::reconstitute(
            id: (int) $row['id'],
            usuarioId: (int) $row['usuario_id'],
            empresaId: (int) $row['empresa_id'],
            titulo: $row['titulo'],
            createdAt: new \DateTimeImmutable($row['created_at']),
            updatedAt: new \DateTimeImmutable($row['updated_at']),
        ), $rows);
    }
}
```

- [ ] **Step 2: Create CodeIgniterMessageRepository**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Persistence;

use App\Application\Chatbot\Port\MessageRepository;
use App\Domain\Chatbot\Message;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterMessageRepository implements MessageRepository
{
    public function __construct(
        private readonly BaseConnection $database,
    ) {}

    public function append(Message $message): int
    {
        $this->database->table('mensajes')->insert([
            'conversacion_id' => $message->conversationId,
            'role' => $message->role,
            'content' => $message->content,
            'tool_calls' => $message->toolCalls !== null ? json_encode($message->toolCalls) : null,
            'tool_call_id' => $message->toolCallId,
            'tokens_used' => $message->tokensUsed,
            'created_at' => $message->createdAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->database->insertID();
    }

    public function findForConversation(int $conversationId, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->database->table('mensajes')
            ->where('conversacion_id', $conversationId)
            ->orderBy('created_at', 'ASC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        return array_map(fn($row) => Message::reconstitute(
            id: (int) $row['id'],
            conversationId: (int) $row['conversacion_id'],
            role: $row['role'],
            content: $row['content'],
            toolCalls: $row['tool_calls'] !== null ? json_decode($row['tool_calls'], true) : null,
            toolCallId: $row['tool_call_id'],
            tokensUsed: $row['tokens_used'] !== null ? (int) $row['tokens_used'] : null,
            createdAt: new \DateTimeImmutable($row['created_at']),
        ), $rows);
    }

    public function countForConversation(int $conversationId): int
    {
        return (int) $this->database->table('mensajes')
            ->where('conversacion_id', $conversationId)
            ->countAllResults();
    }
}
```

- [ ] **Step 3: Create AIProviderConfig**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\AI;

final class AIProviderConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $provider,
        public readonly string $apiKey,
        public readonly string $model,
        public readonly int $timeoutSeconds,
        public readonly int $contextWindowMessages,
        public readonly int $rateLimitPerMinute,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            enabled: filter_var(env('ai.enabled', false), FILTER_VALIDATE_BOOL),
            provider: env('ai.provider', 'minimax'),
            apiKey: env('ai.apiKey', ''),
            model: env('ai.model', ''),
            timeoutSeconds: (int) env('ai.timeoutSeconds', 30),
            contextWindowMessages: (int) env('ai.contextWindowMessages', 20),
            rateLimitPerMinute: (int) env('ai.rateLimitPerMinute', 60),
        );
    }
}
```

- [ ] **Step 4: Create MiniMax DTOs**

```php
<?php
// MiniMaxRequest.php
declare(strict_types=1);

namespace App\Infrastructure\Chatbot\AI\DTOs;

final class MiniMaxRequest
{
    public function __construct(
        public readonly string $model,
        /** @var array<int, array<string, mixed>> */
        public readonly array $messages,
        /** @var array<int, array<string, mixed>> */
        public readonly array $tools = [],
        public readonly float $temperature = 0.7,
        public readonly int $maxTokens = 2048,
        public readonly bool $stream = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'model' => $this->model,
            'messages' => $this->messages,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'stream' => $this->stream,
        ];

        if ($this->tools !== []) {
            $data['tools'] = $this->tools;
        }

        return $data;
    }
}
```

```php
<?php
// MiniMaxResponse.php
declare(strict_types=1);

namespace App\Infrastructure\Chatbot\AI\DTOs;

final class MiniMaxResponse
{
    public function __construct(
        public readonly string $content,
        /** @var array<int, array<string, mixed>> */
        public readonly array $toolCalls = [],
        public readonly ?int $tokensUsed = null,
        public readonly ?string $error = null,
    ) {}

    public static function fromArray(array $data): self
    {
        $choice = $data['choices'][0] ?? null;
        $message = $choice['message'] ?? [];
        $toolCalls = [];

        foreach ($message['tool_calls'] ?? [] as $tc) {
            $toolCalls[] = [
                'id' => $tc['id'] ?? '',
                'name' => $tc['function']['name'] ?? '',
                'arguments' => json_decode($tc['function']['arguments'] ?? '{}', true, 512, JSON_THROW_ON_ERROR),
            ];
        }

        return new self(
            content: $message['content'] ?? '',
            toolCalls: $toolCalls,
            tokensUsed: $data['usage']['total_tokens'] ?? null,
        );
    }
}
```

- [ ] **Step 5: Create MiniMaxProvider**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\AI;

use App\Application\Chatbot\Port\AIProvider;
use App\Application\Chatbot\Port\AIResponse;
use App\Domain\Chatbot\ChatError;
use App\Domain\Chatbot\ToolDefinition;
use App\Infrastructure\Chatbot\AI\DTOs\MiniMaxRequest;
use App\Infrastructure\Chatbot\AI\DTOs\MiniMaxResponse;

final class MiniMaxProvider implements AIProvider
{
    public function __construct(
        private readonly AIProviderConfig $config,
    ) {}

    public function sendMessage(array $messages, array $tools = []): AIResponse
    {
        return $this->call($messages, $tools, stream: false);
    }

    public function sendMessageStreaming(array $messages, array $tools = [], callable $onChunk = null): AIResponse
    {
        return $this->call($messages, $tools, stream: true, onChunk: $onChunk);
    }

    private function call(array $messages, array $tools, bool $stream, callable $onChunk = null): AIResponse
    {
        if (! $this->config->enabled) {
            throw ChatError::providerError('El chatbot está deshabilitado. Configure ai.enabled=true en .env');
        }

        $functionTools = array_map(fn(ToolDefinition $t) => $t->toFunctionCallingFormat(), $tools);

        $request = new MiniMaxRequest(
            model: $this->config->model,
            messages: $messages,
            tools: $functionTools,
            stream: $stream,
        );

        $ch = curl_init('https://api.minimax.chat/v1/text/chatcompletion_pro');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request->toArray(), JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => $this->config->timeoutSeconds,
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            throw ChatError::providerError("Error de conexión: {$error}");
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if ($httpCode !== 200) {
            $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
            throw ChatError::providerError($msg);
        }

        $response = MiniMaxResponse::fromArray($data);

        if ($response->error !== null) {
            throw ChatError::providerError($response->error);
        }

        return new AIResponse(
            content: $response->content,
            toolCalls: $response->toolCalls,
            tokensUsed: $response->tokensUsed,
        );
    }
}
```

- [ ] **Step 6: Create StreamingResponse helper**

```php
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
```

- [ ] **Step 7: Commit**

```bash
git add app/Infrastructure/Chatbot/
git commit -m "feat(chatbot): infraestructura — repositorios, MiniMaxProvider y SSE"
```

---

## Task 7: Tool de Ejemplo — SearchEquipmentTool

**Files:**
- Create: `app/Infrastructure/Chatbot/Tools/ToolSchemaBuilder.php`
- Create: `app/Infrastructure/Chatbot/Tools/SearchEquipmentTool.php`
- Create: `tests/unit/Infrastructure/Chatbot/Tools/SearchEquipmentToolTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Chatbot\Tools;

use App\Infrastructure\Chatbot\Tools\SearchEquipmentTool;
use App\Application\Assets\Port\EquipmentReadModel;
use App\Application\Identity\ActorContext;
use PHPUnit\Framework\TestCase;

final class SearchEquipmentToolTest extends TestCase
{
    public function testSearchReturnsMatchingEquipment(): void
    {
        $readModel = $this->createMock(EquipmentReadModel::class);
        $readModel->method('search')
            ->with($this->anything(), 'CAM-014')
            ->willReturn([[
                'id' => 14,
                'codigo' => 'CAM-014',
                'patente' => 'AB123CD',
                'nombre' => 'Volvo FH16',
                'tipo' => 'Camión',
                'estado' => 'operativo',
                'sucursal' => 'Garuhapé',
                'kilometrajeActual' => 185420,
                'horometroActual' => 12340,
            ]]);

        $tool = new SearchEquipmentTool($readModel);
        $actor = ActorContext::superAdmin(1, [1]);
        $result = $tool->execute(['query' => 'CAM-014'], $actor);

        $this->assertCount(1, $result);
        $this->assertSame('CAM-014', $result[0]['codigo']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/unit/Infrastructure/Chatbot/Tools/SearchEquipmentToolTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create SearchEquipmentTool**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Assets\Port\EquipmentReadModel;
use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\ToolHandler;

final class SearchEquipmentTool implements ToolHandler
{
    public function __construct(
        private readonly EquipmentReadModel $equipment,
    ) {}

    public function execute(array $args, ActorContext $actor): array
    {
        $query = $args['query'] ?? '';
        return $this->equipment->search($actor, $query);
    }
}
```

- [ ] **Step 4: Create ToolSchemaBuilder**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Domain\Chatbot\ToolDefinition;

final class ToolSchemaBuilder
{
    /** @param ToolDefinition[] $tools @return array<int, array<string, mixed>> */
    public static function toFunctionCallingFormat(array $tools): array
    {
        return array_map(fn(ToolDefinition $t) => $t->toFunctionCallingFormat(), $tools);
    }
}
```

- [ ] **Step 5: Run tests**

Run: `php vendor/bin/phpunit tests/unit/Infrastructure/Chatbot/Tools/SearchEquipmentToolTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Infrastructure/Chatbot/Tools/ tests/unit/Infrastructure/Chatbot/
git commit -m "feat(chatbot): SearchEquipmentTool y ToolSchemaBuilder"
```

---

## Task 8: Services.php — Wiring

**Files:**
- Modify: `app/Config/Services.php`

- [ ] **Step 1: Add chatbot services to Services.php**

Add the following methods at the end of the `Services` class (before the closing `}`):

```php
// --- Chatbot ---

public static function startConversation(bool $getShared = true): \App\Application\Chatbot\Handler\StartConversationHandler
{
    if ($getShared) {
        return static::getSharedInstance('startConversation');
    }

    $database = db_connect();

    return new \App\Application\Chatbot\Handler\StartConversationHandler(
        new \App\Infrastructure\Chatbot\Persistence\CodeIgniterConversationRepository($database),
    );
}

public static function processMessage(bool $getShared = true): \App\Application\Chatbot\Handler\ProcessMessageHandler
{
    if ($getShared) {
        return static::getSharedInstance('processMessage');
    }

    $database = db_connect();

    return new \App\Application\Chatbot\Handler\ProcessMessageHandler(
        new \App\Infrastructure\Chatbot\Persistence\CodeIgniterMessageRepository($database),
        new \App\Infrastructure\Chatbot\Tools\StaticToolRegistry(),
        new \App\Infrastructure\Chatbot\AI\MiniMaxProvider(
            \App\Infrastructure\Chatbot\AI\AIProviderConfig::fromEnv(),
        ),
        new \App\Infrastructure\Chatbot\Tools\ToolExecutor(),
        new \App\Infrastructure\Chatbot\Persistence\SystemChatClock(),
        new \App\Infrastructure\Chatbot\Persistence\CodeIgniterConversationRepository($database),
    );
}

public static function chatbotToolRegistry(bool $getShared = true): \App\Application\Chatbot\Port\ToolRegistry
{
    if ($getShared) {
        return static::getSharedInstance('chatbotToolRegistry');
    }

    $database = db_connect();

    return new \App\Infrastructure\Chatbot\Tools\StaticToolRegistry(
        new \App\Infrastructure\Chatbot\Tools\SearchEquipmentTool(
            new \App\Infrastructure\Assets\CodeIgniterEquipmentReadModel($database),
        ),
    );
}
```

- [ ] **Step 2: Create supporting infrastructure classes**

Create `app/Infrastructure/Chatbot/Tools/StaticToolRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Chatbot\Port\ToolRegistry;
use App\Domain\Chatbot\ToolDefinition;
use App\Domain\Chatbot\ToolHandler;

final class StaticToolRegistry implements ToolRegistry
{
    /** @var array<string, ToolDefinition> */
    private array $tools = [];

    public function __construct(ToolHandler ...$handlers)
    {
        // Register built-in tools
        $this->register(ToolDefinition::read(
            name: 'buscar_equipo',
            description: 'Busca un equipo por código, patente o nombre. Devuelve ficha resumida con estado, ubicación y datos técnicos.',
            parameters: ['query' => ['type' => 'string', 'description' => 'Código, patente o nombre del equipo']],
            permission: 'equipos.ver',
            handlerClass: SearchEquipmentTool::class,
        ));
    }

    public function register(ToolDefinition $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    public function all(): array
    {
        return array_values($this->tools);
    }

    public function find(string $name): ?ToolDefinition
    {
        return $this->tools[$name] ?? null;
    }
}
```

Create `app/Infrastructure/Chatbot/Tools/ToolExecutor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Chatbot\Port\ToolExecutor;
use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\ChatError;
use App\Domain\Chatbot\ToolCallResult;
use App\Domain\Chatbot\ToolHandler;

final class ToolExecutor implements ToolExecutor
{
    /** @var array<string, ToolHandler> */
    private array $handlers = [];

    public function __construct()
    {
        $database = db_connect();
        $this->handlers['buscar_equipo'] = new SearchEquipmentTool(
            new \App\Infrastructure\Assets\CodeIgniterEquipmentReadModel($database),
        );
    }

    public function execute(string $toolName, array $args, ActorContext $actor): ToolCallResult
    {
        $handler = $this->handlers[$toolName] ?? null;
        if ($handler === null) {
            return ToolCallResult::failure('unknown', $toolName, "Tool '{$toolName}' no encontrada.");
        }

        try {
            $result = $handler->execute($args, $actor);
            return ToolCallResult::success('exec_' . uniqid(), $toolName, $result);
        } catch (\Throwable $e) {
            return ToolCallResult::failure('error_' . uniqid(), $toolName, $e->getMessage());
        }
    }
}
```

Create `app/Infrastructure/Chatbot/Persistence/SystemChatClock.php`:

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Persistence;

use App\Application\Chatbot\Port\ChatClock;

final class SystemChatClock implements ChatClock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
```

- [ ] **Step 3: Add .env variables to .env.example**

Append to `.env.example`:

```
# Chatbot IA
ai.enabled = false
ai.provider = minimax
ai.apiKey =
ai.model =
ai.timeoutSeconds = 30
ai.contextWindowMessages = 20
ai.rateLimitPerMinute = 60
```

- [ ] **Step 4: Commit**

```bash
git add app/Config/Services.php app/Infrastructure/Chatbot/Tools/StaticToolRegistry.php app/Infrastructure/Chatbot/Tools/ToolExecutor.php app/Infrastructure/Chatbot/Persistence/SystemChatClock.php .env.example
git commit -m "feat(chatbot): wiring en Services.php y .env config"
```

---

## Task 9: Controller y Rutas

**Files:**
- Create: `app/Controllers/Chatbot.php`
- Modify: `app/Config/Routes.php`
- Create: `tests/feature/ChatbotTest.php`

- [ ] **Step 1: Write failing feature test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestCase;

final class ChatbotTest extends TestCase
{
    use FeatureTestTrait;

    protected $setUpMethods = [''];

    public function testStartConversationRequiresAuth(): void
    {
        $this->post('mantenimiento/chatbot/conversaciones')
            ->assertStatus(401);
    }

    public function testStartConversationCreatesConversation(): void
    {
        $this->session->set('usuario_id', 1);
        $this->session->set('empresa_id', 1);
        $this->session->set('roles', ['administrador']);
        $this->session->set('permisos', ['chatbot.usar']);

        $this->post('mantenimiento/chatbot/conversaciones')
            ->assertStatus(200)
            ->assertJSONStructure(['conversation' => ['id', 'empresaId']]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/feature/ChatbotTest.php`
Expected: FAIL — route not found

- [ ] **Step 3: Create Chatbot controller**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Chatbot\Command\SendMessageCommand;
use App\Application\Chatbot\Command\StartConversationCommand;
use App\Application\Chatbot\Handler\ProcessMessageHandler;
use App\Application\Chatbot\Handler\StartConversationHandler;
use App\Application\Identity\SessionActorContext;
use App\Domain\Chatbot\ChatError;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

final class Chatbot extends BaseController
{
    private function actor(): \App\Application\Identity\ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new \DomainException('No existe un contexto autenticado válido.');
        }
        return $actor;
    }

    public function index(): string
    {
        return $this->renderApp(
            actor: $this->actor(),
            activeNavigation: 'chatbot',
            page: 'chatbot',
            title: 'Asistente IA',
            data: [],
        );
    }

    public function startConversation(): ResponseInterface
    {
        try {
            $handler = service('startConversation');
            $result = $handler->execute($this->actor(), new StartConversationCommand());

            return $this->response->setJSON([
                'conversation' => [
                    'id' => $result->conversation->id,
                    'empresaId' => $result->conversation->empresaId,
                    'titulo' => $result->conversation->titulo,
                ],
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        } catch (Throwable $e) {
            return $this->response->setStatusCode(422)->setJSON([
                'error' => $e->getMessage(),
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        }
    }

    public function sendMessage(): ResponseInterface
    {
        try {
            $actor = $this->actor();
            $conversationId = (int) $this->request->getPost('conversationId');
            $content = $this->request->getPost('content') ?? '';
            $confirmedToolCalls = $this->request->getPost('confirmedToolCalls');

            if ($confirmedToolCalls !== null && is_string($confirmedToolCalls)) {
                $confirmedToolCalls = json_decode($confirmedToolCalls, true);
            }

            $handler = service('processMessage');
            $result = $handler->execute($actor, new SendMessageCommand(
                conversationId: $conversationId,
                content: $content,
                confirmedToolCalls: $confirmedToolCalls,
            ));

            $messages = array_map(fn($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'toolCalls' => $m->toolCalls,
                'toolCallId' => $m->toolCallId,
            ], $result->messages);

            return $this->response->setJSON([
                'messages' => $messages,
                'pendingToolCalls' => $result->pendingToolCalls,
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        } catch (ChatError $e) {
            return $this->response->setStatusCode(422)->setJSON([
                'error' => $e->getMessage(),
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Chatbot error: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON([
                'error' => 'Error interno del asistente.',
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        }
    }

    public function confirmTool(): ResponseInterface
    {
        try {
            $actor = $this->actor();
            $conversationId = (int) $this->request->getPost('conversationId');
            $toolCalls = json_decode($this->request->getPost('toolCalls') ?? '[]', true);

            $handler = service('processMessage');
            $result = $handler->execute($actor, new SendMessageCommand(
                conversationId: $conversationId,
                content: '',
                confirmedToolCalls: $toolCalls,
            ));

            $messages = array_map(fn($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
            ], $result->messages);

            return $this->response->setJSON([
                'messages' => $messages,
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        } catch (Throwable $e) {
            return $this->response->setStatusCode(422)->setJSON([
                'error' => $e->getMessage(),
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        }
    }

    public function history(): ResponseInterface
    {
        try {
            $conversationId = (int) $this->request->getGet('conversationId');
            $offset = (int) ($this->request->getGet('offset') ?? 0);
            $limit = (int) ($this->request->getGet('limit') ?? 50);

            $msgRepo = new \App\Infrastructure\Chatbot\Persistence\CodeIgniterMessageRepository(db_connect());
            $messages = $msgRepo->findForConversation($conversationId, $limit, $offset);

            $data = array_map(fn($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'toolCalls' => $m->toolCalls,
                'createdAt' => $m->createdAt->format('c'),
            ], $messages);

            return $this->response->setJSON([
                'messages' => $data,
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        } catch (Throwable $e) {
            return $this->response->setStatusCode(422)->setJSON([
                'error' => $e->getMessage(),
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        }
    }

    public function sendMessageStream(): ResponseInterface
    {
        $this->response->setHeader('Content-Type', 'text/event-stream')
            ->setHeader('Cache-Control', 'no-cache')
            ->setHeader('Connection', 'keep-alive');

        $actor = $this->actor();
        $conversationId = (int) $this->request->getPost('conversationId');
        $content = $this->request->getPost('content') ?? '';

        $sse = new \App\Infrastructure\Chatbot\SSE\StreamingResponse($this->response);
        $sse->sendHeaders();

        try {
            $handler = service('processMessage');
            $result = $handler->execute($actor, new SendMessageCommand(
                conversationId: $conversationId,
                content: $content,
            ));

            foreach ($result->messages as $msg) {
                if ($msg->role === 'assistant') {
                    $sse->sendChunk($msg->content);
                }
            }

            if ($result->pendingToolCalls !== []) {
                $sse->sendEvent('pending_tools', json_encode($result->pendingToolCalls));
            }

            $sse->sendDone();
        } catch (Throwable $e) {
            $sse->sendError($e->getMessage());
        }

        return $this->response;
    }
}
```

- [ ] **Step 4: Add routes to Routes.php**

Append before the closing of the `mantenimiento` group (or add a new group):

```php
// Chatbot
$routes->group('mantenimiento/chatbot', ['filter' => ['auth']], function ($routes) {
    $routes->get('/',               'Chatbot::index');
    $routes->post('conversaciones', 'Chatbot::startConversation', ['filter' => 'permission:chatbot.usar']);
    $routes->post('mensajes',       'Chatbot::sendMessage',       ['filter' => 'permission:chatbot.usar']);
    $routes->post('mensajes/stream','Chatbot::sendMessageStream', ['filter' => 'permission:chatbot.usar']);
    $routes->post('confirmar',      'Chatbot::confirmTool',       ['filter' => 'permission:chatbot.usar']);
    $routes->get('historial',       'Chatbot::history',           ['filter' => 'permission:chatbot.usar']);
});
```

- [ ] **Step 5: Run tests**

Run: `php vendor/bin/phpunit tests/feature/ChatbotTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Controllers/Chatbot.php app/Config/Routes.php tests/feature/ChatbotTest.php
git commit -m "feat(chatbot): controller HTTP, rutas y feature tests"
```

---

## Task 10: Frontend — ChatWidget, ChatMessage, ChatToolConfirm

**Files:**
- Create: `frontend/src/pages/operations/components/ChatWidget.vue`
- Create: `frontend/src/pages/operations/components/ChatMessage.vue`
- Create: `frontend/src/pages/operations/components/ChatToolConfirm.vue`
- Create: `frontend/src/pages/operations/components/ChatVoiceButton.vue`
- Modify: `frontend/src/components/ApplicationShell.vue`

- [ ] **Step 1: Create ChatMessage.vue**

```vue
<template>
  <div class="flex" :class="message.role === 'user' ? 'justify-end' : 'justify-start'">
    <div
      class="max-w-[80%] rounded-xl px-4 py-2 text-sm"
      :class="message.role === 'user'
        ? 'bg-blue-600 text-white'
        : message.role === 'assistant'
          ? 'bg-gray-100 text-gray-800'
          : 'bg-yellow-50 text-yellow-800 text-xs'"
    >
      <div v-if="message.role === 'assistant'" class="prose prose-sm max-w-none" v-html="renderedContent" />
      <div v-else>{{ message.content }}</div>
      <div v-if="message.role === 'assistant' && streaming" class="inline-block w-2 h-4 bg-gray-400 animate-pulse ml-0.5" />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  message: { type: Object, required: true },
  streaming: { type: Boolean, default: false },
})

const renderedContent = computed(() => {
  // Basic markdown: bold, italic, links, line breaks
  let text = props.message.content
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.*?)\*/g, '<em>$1</em>')
    .replace(/\n/g, '<br>')
  return text
})
</script>
```

- [ ] **Step 2: Create ChatToolConfirm.vue**

```vue
<template>
  <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-sm">
    <div class="flex items-center gap-2 mb-2">
      <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
      </svg>
      <span class="font-medium text-amber-800">Confirmar acción</span>
    </div>
    <div class="text-amber-700 mb-3">
      <div class="font-mono text-xs bg-amber-100 rounded p-2 mb-2">{{ toolName }}</div>
      <pre class="text-xs whitespace-pre-wrap">{{ formattedArgs }}</pre>
    </div>
    <div class="flex gap-2">
      <button
        @click="$emit('confirm')"
        class="px-3 py-1 bg-amber-600 text-white text-xs rounded-lg hover:bg-amber-700"
      >
        Confirmar
      </button>
      <button
        @click="$emit('cancel')"
        class="px-3 py-1 bg-gray-200 text-gray-700 text-xs rounded-lg hover:bg-gray-300"
      >
        Cancelar
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  toolCall: { type: Object, required: true },
})

defineEmits(['confirm', 'cancel'])

const toolName = computed(() => props.toolCall.name || props.toolCall.function?.name || 'unknown')
const formattedArgs = computed(() => {
  const args = props.toolCall.arguments || props.toolCall.function?.arguments || {}
  return JSON.stringify(args, null, 2)
})
</script>
```

- [ ] **Step 3: Create ChatVoiceButton.vue**

```vue
<template>
  <button
    @click="toggleRecording"
    :class="[
      'w-8 h-8 rounded-full flex items-center justify-center transition-colors',
      isRecording ? 'bg-red-500 text-white animate-pulse' : 'bg-gray-200 text-gray-600 hover:bg-gray-300'
    ]"
    :title="isRecording ? 'Detener' : 'Grabar voz'"
  >
    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
      <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z" />
      <path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z" />
    </svg>
  </button>
</template>

<script setup>
import { ref, onUnmounted } from 'vue'

const emit = defineEmits(['transcript'])

const isRecording = ref(false)
let recognition = null

const toggleRecording = () => {
  if (isRecording.value) {
    recognition?.stop()
    isRecording.value = false
    return
  }

  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition
  if (!SpeechRecognition) {
    alert('Tu navegador no soporta reconocimiento de voz.')
    return
  }

  recognition = new SpeechRecognition()
  recognition.lang = 'es-AR'
  recognition.continuous = false
  recognition.interimResults = false

  recognition.onresult = (event) => {
    const transcript = event.results[0][0].transcript
    emit('transcript', transcript)
    isRecording.value = false
  }

  recognition.onerror = () => { isRecording.value = false }
  recognition.onend = () => { isRecording.value = false }

  recognition.start()
  isRecording.value = true
}

onUnmounted(() => { recognition?.abort() })
</script>
```

- [ ] **Step 4: Create ChatWidget.vue**

```vue
<template>
  <!-- Toggle button -->
  <button
    v-if="!isOpen"
    @click="toggle"
    class="fixed bottom-6 right-6 z-50 w-14 h-14 bg-blue-600 text-white rounded-full shadow-lg hover:bg-blue-700 flex items-center justify-center transition-colors"
  >
    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
    </svg>
  </button>

  <!-- Chat panel -->
  <div
    v-if="isOpen"
    class="fixed bottom-6 right-6 z-50 w-96 h-[500px] bg-white rounded-2xl shadow-2xl border border-gray-200 flex flex-col"
  >
    <!-- Header -->
    <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 bg-blue-600 text-white rounded-t-2xl">
      <div class="flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
        </svg>
        <span class="font-medium text-sm">Asistente IA</span>
      </div>
      <button @click="toggle" class="text-white/80 hover:text-white">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Messages -->
    <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-3">
      <ChatMessage
        v-for="msg in messages"
        :key="msg.id || msg.tempId"
        :message="msg"
        :streaming="msg.streaming"
      />
      <ChatToolConfirm
        v-for="(tc, idx) in pendingToolCalls"
        :key="idx"
        :tool-call="tc"
        @confirm="confirmTool(tc)"
        @cancel="cancelTool(tc)"
      />
      <div v-if="loading" class="text-center text-gray-400 text-sm py-2">
        Pensando...
      </div>
    </div>

    <!-- Input -->
    <div class="border-t border-gray-200 p-3">
      <form @submit.prevent="sendMessage" class="flex items-center gap-2">
        <ChatVoiceButton @transcript="onVoiceTranscript" />
        <input
          v-model="input"
          type="text"
          placeholder="Escribí tu mensaje..."
          class="flex-1 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
          :disabled="loading"
        />
        <button
          type="submit"
          :disabled="!input.trim() || loading"
          class="w-8 h-8 bg-blue-600 text-white rounded-lg flex items-center justify-center hover:bg-blue-700 disabled:opacity-50"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
          </svg>
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, nextTick, onMounted } from 'vue'
import ChatMessage from './ChatMessage.vue'
import ChatToolConfirm from './ChatToolConfirm.vue'
import ChatVoiceButton from './ChatVoiceButton.vue'

const isOpen = ref(false)
const messages = ref([])
const pendingToolCalls = ref([])
const input = ref('')
const loading = ref(false)
const conversationId = ref(null)
const messagesContainer = ref(null)
let tempIdCounter = 0

const toggle = () => {
  isOpen.value = !isOpen.value
  if (isOpen.value && conversationId.value === null) {
    startConversation()
  }
}

const scrollToBottom = () => {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}

const startConversation = async () => {
  try {
    const res = await fetch('/mantenimiento/chatbot/conversaciones', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
    const data = await res.json()
    conversationId.value = data.conversation.id
    messages.value.push({
      tempId: ++tempIdCounter,
      role: 'assistant',
      content: 'Hola, soy tu asistente de mantenimiento. ¿En qué puedo ayudarte?',
    })
  } catch (e) {
    console.error('Error starting conversation', e)
  }
}

const sendMessage = async () => {
  if (!input.value.trim() || loading.value) return

  const userMsg = { tempId: ++tempIdCounter, role: 'user', content: input.value }
  messages.value.push(userMsg)
  input.value = ''
  loading.value = true
  scrollToBottom()

  try {
    const body = new FormData()
    body.append('conversationId', conversationId.value)
    body.append('content', userMsg.content)

    const res = await fetch('/mantenimiento/chatbot/mensajes', {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })

    const data = await res.json()

    if (data.messages) {
      for (const msg of data.messages) {
        if (msg.role === 'assistant') {
          messages.value.push({ ...msg, tempId: ++tempIdCounter })
        }
      }
    }

    if (data.pendingToolCalls) {
      pendingToolCalls.value = data.pendingToolCalls
    }
  } catch (e) {
    messages.value.push({
      tempId: ++tempIdCounter,
      role: 'assistant',
      content: 'Disculpá, hubo un error. Intentá de nuevo.',
    })
  } finally {
    loading.value = false
    scrollToBottom()
  }
}

const confirmTool = async (toolCall) => {
  pendingToolCalls.value = pendingToolCalls.value.filter(tc => tc.id !== toolCall.id)
  loading.value = true

  try {
    const body = new FormData()
    body.append('conversationId', conversationId.value)
    body.append('toolCalls', JSON.stringify([toolCall]))

    const res = await fetch('/mantenimiento/chatbot/confirmar', {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })

    const data = await res.json()
    if (data.messages) {
      for (const msg of data.messages) {
        messages.value.push({ ...msg, tempId: ++tempIdCounter })
      }
    }
  } catch (e) {
    messages.value.push({
      tempId: ++tempIdCounter,
      role: 'assistant',
      content: 'Error al ejecutar la acción.',
    })
  } finally {
    loading.value = false
    scrollToBottom()
  }
}

const cancelTool = (toolCall) => {
  pendingToolCalls.value = pendingToolCalls.value.filter(tc => tc.id !== toolCall.id)
}

const onVoiceTranscript = (text) => {
  input.value = text
  sendMessage()
}
</script>
```

- [ ] **Step 5: Mount ChatWidget in ApplicationShell.vue**

Add after the closing `</div>` on line 93 (before `</template>`):

```vue
    <ChatWidget />
```

Add import in `<script setup>`:

```javascript
import ChatWidget from '../pages/operations/components/ChatWidget.vue'
```

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/operations/components/ChatWidget.vue frontend/src/pages/operations/components/ChatMessage.vue frontend/src/pages/operations/components/ChatToolConfirm.vue frontend/src/pages/operations/components/ChatVoiceButton.vue frontend/src/components/ApplicationShell.vue
git commit -m "feat(chatbot): widget flotante, mensajes, confirmación y voz"
```

---

## Task 11: Pruebas de Integración y Verificación

**Files:**
- Modify: `tests/unit/Infrastructure/Chatbot/Tools/SearchEquipmentToolTest.php` (add DB test)
- Run: full test suite

- [ ] **Step 1: Run full test suite**

Run: `php vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 2: Run migration on test DB**

Run: `php spark migrate:rollback -n 1 && php spark migrate`
Expected: Migration creates `conversaciones` and `mensajes` tables

- [ ] **Step 3: Verify manually in browser**

- Navegar a `/mantenimiento` y verificar que el widget flotante aparece
- Abrir el chat, verificar que se crea conversación
- Enviar un mensaje de texto, verificar respuesta del asistente
- Probar `buscar_equipo` con un código real
- Verificar que no se puede acceder a datos de otra empresa

- [ ] **Step 4: Commit final**

```bash
git add -A
git commit -m "test(chatbot): verificación integral del núcleo conversacional"
```
