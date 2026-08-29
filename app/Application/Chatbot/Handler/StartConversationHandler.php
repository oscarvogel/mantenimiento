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