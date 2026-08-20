<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Chatbot;

use App\Application\Chatbot\Command\StartConversationCommand;
use App\Application\Chatbot\Handler\StartConversationHandler;
use App\Application\Chatbot\Port\ConversationRepository;
use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\Conversation;
use PHPUnit\Framework\TestCase;

final class StartConversationHandlerTest extends TestCase
{
    public function testCreatesConversationWithActorScope(): void
    {
        $repo = new class implements ConversationRepository {
            public ?Conversation $saved = null;

            public function save(Conversation $c): int
            {
                $this->saved = $c;
                return 1;
            }

            public function find(int $id): ?Conversation
            {
                return null;
            }

            public function findByUser(int $u, int $e, int $l = 20, int $o = 0): array
            {
                return [];
            }
        };

        $handler = new StartConversationHandler($repo);
        $actor = ActorContext::fromArray([
            'user_id'              => 1,
            'company_id'           => 1,
            'super_admin'          => false,
            'all_company_branches' => false,
            'roles'                => ['administrador'],
            'permissions'          => ['chatbot.usar'],
            'branch_ids'           => [1],
        ]);
        $result = $handler->execute($actor, new StartConversationCommand());

        $this->assertSame(1, $result->conversation->empresaId);
        $this->assertSame(1, $result->conversation->usuarioId);
        $this->assertSame(1, $result->conversation->id);
    }
}