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

    public function execute(ActorContext $actor, SendMessageCommand $command, ?callable $onChunk = null): MessageProcessedResult
    {
        $conversation = $this->conversations->find($command->conversationId);
        if ($conversation === null) {
            throw new \DomainException('La conversación no existe.');
        }
        if (! $actor->canAccessCompany($conversation->empresaId)) {
            throw new \DomainException('No tenés acceso a esta conversación.');
        }

        if ($command->confirmedToolCalls !== null) {
            return $this->executeConfirmedToolCalls($actor, $command, $onChunk);
        }

        $userMessage = Message::user($command->conversationId, $command->content);
        $this->messages->append($userMessage);

        $history = $this->messages->findForConversation($command->conversationId, limit: 20);

        $toolsForUser = $this->toolsForActor($actor);

        $aiResponse = $onChunk === null
            ? $this->aiProvider->sendMessage($this->toProviderMessages($history), $toolsForUser)
            : $this->aiProvider->sendMessageStreaming($this->toProviderMessages($history), $toolsForUser, $onChunk);

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
                    $toolMsg = $this->buildToolMessage($command->conversationId, $tc, $result);
                    $this->messages->append($toolMsg);
                }
            }

            if ($pendingWrites !== []) {
                return new MessageProcessedResult(
                    messages: [$userMessage],
                    pendingToolCalls: $pendingWrites,
                    streaming: $onChunk !== null,
                );
            }

            $historyAfterTools = $this->messages->findForConversation($command->conversationId, limit: 20);
            $aiResponse = $onChunk === null
                ? $this->aiProvider->sendMessage($this->toProviderMessages($historyAfterTools), $toolsForUser)
                : $this->aiProvider->sendMessageStreaming($this->toProviderMessages($historyAfterTools), $toolsForUser, $onChunk);
        }

        $assistantMessage = Message::assistant(
            $command->conversationId,
            $aiResponse->content,
            $aiResponse->tokensUsed,
        );
        $this->messages->append($assistantMessage);

        return new MessageProcessedResult(messages: [$userMessage, $assistantMessage], streaming: $onChunk !== null);
    }

    /**
     * Ejecuta los tool_calls que el usuario confirmó desde el frontend
     * sin volver a invocar al proveedor de IA. Persiste cada tool call con
     * resultado y luego pide al proveedor la respuesta final.
     */
    private function executeConfirmedToolCalls(ActorContext $actor, SendMessageCommand $command, ?callable $onChunk = null): MessageProcessedResult
    {
        $executed = [];
        foreach ($command->confirmedToolCalls as $tc) {
            $name = (string) ($tc['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $toolDef = $this->toolRegistry->find($name);
            if ($toolDef === null || ! $toolDef->isWrite) {
                continue;
            }

            $result = $this->toolExecutor->execute($name, $tc['arguments'] ?? [], $actor);
            $toolMsg = $this->buildToolMessage($command->conversationId, $tc, $result);
            $this->messages->append($toolMsg);
            $executed[] = $toolMsg;
        }

        $history = $this->messages->findForConversation($command->conversationId, limit: 20);
        $aiResponse = $onChunk === null
            ? $this->aiProvider->sendMessage($this->toProviderMessages($history), $this->toolsForActor($actor))
            : $this->aiProvider->sendMessageStreaming($this->toProviderMessages($history), $this->toolsForActor($actor), $onChunk);

        $assistantMessage = Message::assistant(
            $command->conversationId,
            $aiResponse->content,
            $aiResponse->tokensUsed,
        );
        $this->messages->append($assistantMessage);

        return new MessageProcessedResult(messages: [$assistantMessage], streaming: $onChunk !== null);
    }

    /**
     * Construye el mensaje de tipo tool con datos normalizados:
     * tool_call_id apunta a la invocación original; tool_calls JSON tiene
     * nombre, argumentos invocados y resultado (éxito/error) para auditoría.
     */
    private function buildToolMessage(int $conversationId, array $tc, \App\Domain\Chatbot\ToolCallResult $result): Message
    {
        return Message::tool(
            conversationId: $conversationId,
            toolCallId: (string) ($tc['id'] ?? ''),
            toolName: (string) ($tc['name'] ?? ''),
            arguments: $tc['arguments'] ?? [],
            result: $result->result,
            success: $result->success,
            errorMessage: $result->errorMessage,
        );
    }

    /**
     * @return ToolDefinition[]
     */
    private function toolsForActor(ActorContext $actor): array
    {
        return array_values(array_filter(
            $this->toolRegistry->all(),
            fn ($tool) => $actor->hasPermission($tool->permission),
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private function toProviderMessages(array $messages): array
    {
        return array_map(fn (Message $m) => [
            'role' => $m->role,
            'content' => $m->content,
        ], $messages);
    }
}
