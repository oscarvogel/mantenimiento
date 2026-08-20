<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Handler;

use App\Application\Chatbot\Command\SendMessageCommand;
use App\Application\Chatbot\Port\AIProvider;
use App\Application\Chatbot\Port\AIResponse;
use App\Application\Chatbot\Port\ChatClock;
use App\Application\Chatbot\Port\ConversationRepository;
use App\Application\Chatbot\Port\MessageRepository;
use App\Application\Chatbot\Port\ToolExecutor;
use App\Application\Chatbot\Port\ToolRegistry;
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

    /** @return array<int, array<string, mixed>> */
    private function toProviderMessages(array $messages): array
    {
        return array_map(fn(Message $m) => [
            'role' => $m->role,
            'content' => $m->content,
        ], $messages);
    }
}
