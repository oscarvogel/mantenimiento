<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Chatbot\Persistence;

use App\Domain\Chatbot\Conversation;
use App\Domain\Chatbot\Message;
use App\Infrastructure\Chatbot\Persistence\CodeIgniterConversationRepository;
use App\Infrastructure\Chatbot\Persistence\CodeIgniterMessageRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class ChatbotRepositoryIntegrationTest extends TestCase
{
    private BaseConnection $database;
    private CodeIgniterConversationRepository $conversations;
    private CodeIgniterMessageRepository $messages;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('Las pruebas de integración del chatbot requieren sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => false,
        ], false);

        $this->database->query('CREATE TABLE conversaciones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario_id INTEGER NOT NULL,
            empresa_id INTEGER NOT NULL,
            titulo VARCHAR(255),
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $this->database->query('CREATE TABLE mensajes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversacion_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            content TEXT NOT NULL,
            tool_calls TEXT,
            tool_call_id VARCHAR(255),
            tokens_used INTEGER,
            created_at TEXT NOT NULL
        )');

        $this->conversations = new CodeIgniterConversationRepository($this->database);
        $this->messages = new CodeIgniterMessageRepository($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testSaveAndFindConversation(): void
    {
        $created = Conversation::create(usuarioId: 1, empresaId: 5, titulo: 'Hola mundo');
        $id = $this->conversations->save($created);

        $found = $this->conversations->find($id);
        $this->assertNotNull($found);
        $this->assertSame(1, $found->usuarioId);
        $this->assertSame(5, $found->empresaId);
        $this->assertSame('Hola mundo', $found->titulo);
    }

    public function testFindByUserScopedByEmpresa(): void
    {
        $this->conversations->save(Conversation::create(usuarioId: 1, empresaId: 5));
        $this->conversations->save(Conversation::create(usuarioId: 1, empresaId: 5));
        $this->conversations->save(Conversation::create(usuarioId: 1, empresaId: 9));

        $sameCompany = $this->conversations->findByUser(1, 5);
        $otherCompany = $this->conversations->findByUser(1, 9);

        $this->assertCount(2, $sameCompany);
        $this->assertCount(1, $otherCompany);
    }

    public function testAppendMessageWithToolCallsPersistsStructuredPayload(): void
    {
        $convId = $this->conversations->save(Conversation::create(usuarioId: 1, empresaId: 5));

        $toolMsg = Message::tool(
            conversationId: $convId,
            toolCallId: 'call_xyz',
            toolName: 'buscar_equipo',
            arguments: ['query' => 'CAM-014'],
            result: [['id' => 14, 'codigo' => 'CAM-014']],
            success: true,
        );

        $id = $this->messages->append($toolMsg);
        $loaded = $this->messages->findForConversation($convId);

        $this->assertCount(1, $loaded);
        $this->assertSame('tool', $loaded[0]->role);
        $this->assertSame('call_xyz', $loaded[0]->toolCallId);
        $this->assertSame('buscar_equipo', $loaded[0]->toolCalls['name']);
        $this->assertSame(['query' => 'CAM-014'], $loaded[0]->toolCalls['arguments']);
        $this->assertSame([['id' => 14, 'codigo' => 'CAM-014']], $loaded[0]->toolCalls['result']);
        $this->assertTrue($loaded[0]->toolCalls['success']);
        $this->assertSame($id, $loaded[0]->id);
    }

    public function testRehydrateHandlesNullToolCalls(): void
    {
        $convId = $this->conversations->save(Conversation::create(usuarioId: 1, empresaId: 5));
        $this->messages->append(Message::user($convId, 'Hola'));

        $loaded = $this->messages->findForConversation($convId);
        $this->assertNull($loaded[0]->toolCalls);
        $this->assertNull($loaded[0]->toolCallId);
    }

    public function testRehydrateIgnoresCorruptedToolCalls(): void
    {
        $convId = $this->conversations->save(Conversation::create(usuarioId: 1, empresaId: 5));
        // Inyectar un JSON malformado a propósito
        $this->database->table('mensajes')->insert([
            'conversacion_id' => $convId,
            'role' => 'tool',
            'content' => '[buscar_equipo ejecutado]',
            'tool_calls' => '{no es json',
            'tool_call_id' => 'call_bad',
            'tokens_used' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $loaded = $this->messages->findForConversation($convId);
        $this->assertCount(1, $loaded);
        $this->assertNull($loaded[0]->toolCalls);
        $this->assertSame('call_bad', $loaded[0]->toolCallId);
    }

    public function testFindOwnedReturnsOnlyWhenUserAndCompanyMatch(): void
    {
        $idUserA = $this->conversations->save(Conversation::create(usuarioId: 1, empresaId: 5));
        $this->conversations->save(Conversation::create(usuarioId: 2, empresaId: 5));
        $this->conversations->save(Conversation::create(usuarioId: 1, empresaId: 9));

        // Usuario A tiene acceso solo a su propia conversación
        $own = $this->conversations->findOwned($idUserA, 1, 5);
        $this->assertNotNull($own);
        $this->assertSame(1, $own->usuarioId);
        $this->assertSame(5, $own->empresaId);

        // Misma conversación pero distinto usuario → null
        $this->assertNull($this->conversations->findOwned($idUserA, 2, 5));

        // Misma conversación pero distinta empresa → null
        $this->assertNull($this->conversations->findOwned($idUserA, 1, 9));

        // Conversación inexistente → null
        $this->assertNull($this->conversations->findOwned(999, 1, 5));
    }
}
