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
use App\Domain\Chatbot\ChatError;
use App\Domain\Chatbot\Conversation;
use App\Domain\Chatbot\Message;
use App\Domain\Chatbot\ToolCallResult;

final class ProcessMessageHandler
{
    private const MAX_TOOL_ROUNDS = 4;

    /**
     * Prompt de sistema: define alcance, idioma y limites del asistente.
     * Se antepone en cada llamada al provider (la API es stateless).
     * Cambiar aqui cambia el comportamiento para TODA conversacion nueva.
     */
    private const SYSTEM_PROMPT = <<<'TXT'
Sos el asistente virtual del sistema de Gestión de Mantenimiento (Vogel Consultoría).
Tu alcance está estrictamente limitado a este sistema y sus datos.

ALCANCE (respondes solo sobre estos temas):
- Equipos / flota: busqueda individual por código, patente o nombre; listados por estado de plan.
- Planes preventivos: estado (AL_DIA, PROXIMO, VENCIDO, SIN_DATOS), intervalos, próximas mantenciones, kilometraje, horas, fechas.
- Lecturas (carga, corrección, regularizaciones) y ordenes de trabajo.
- Catálogo de servicios de mantenimiento y catálogo de tareas.
- Sucursales y usuarios del sistema (dentro del alcance del usuario actual).

FORMATO:
- Responde siempre en español rioplatense (Argentina), en forma breve y profesional.
- Cuando uses datos del sistema, mencionalos explícitamente; nunca inventes valores.
- Si una herramienta devuelve error, informa el mensaje tal cual sin reintentarla por tu cuenta.

FUERA DE ALCANCE (responde exactamente asi, en una sola oracion y sin mas detalle):
"Esa consulta está fuera del alcance de este sistema. Puedo ayudarte únicamente con temas de mantenimiento y gestión de flota."

PROHIBIDO:
- Dar consejos médicos, legales, financieros o de cualquier otra especialidad.
- Opinar sobre política, religión u otros temas no relacionados al mantenimiento.
- Compartir credenciales, datos personales o internals del sistema.
TXT;

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
        $conversation = $this->loadAccessibleConversation($actor, $command->conversationId);

        if ($command->confirmedToolCalls !== null) {
            return $this->executeConfirmedToolCalls($actor, $command, $conversation, $onChunk);
        }

        $userMessage = Message::user($command->conversationId, $command->content);
        $this->messages->append($userMessage);

        $history = $this->messages->findForConversation($command->conversationId, limit: 20);
        $providerMessages = $this->withSystemPrompt($this->toProviderMessages($history));
        $toolsForUser = $this->toolsForActor($actor);
        $aiResponse = $this->askProvider($providerMessages, $toolsForUser, $onChunk);

        $round = 0;
        while ($aiResponse->toolCalls !== []) {
            if ($round >= self::MAX_TOOL_ROUNDS) {
                throw ChatError::providerError('Se alcanzó el límite de pasos de herramientas para esta consulta.');
            }
            $round++;

            $pendingWrites = [];
            $toolProviderMessages = [];

            foreach ($aiResponse->toolCalls as $tc) {
                $toolDef = $this->toolRegistry->find((string) ($tc['name'] ?? ''));
                if ($toolDef === null) {
                    continue;
                }

                if ($toolDef->isWrite && $toolDef->confirmationRequired) {
                    $pendingWrites[] = $tc;
                    continue;
                }

                $result = $this->toolExecutor->execute($tc['name'], $tc['arguments'], $actor);
                $toolMsg = $this->buildToolMessage($command->conversationId, $tc, $result);
                $this->messages->append($toolMsg);
                $toolProviderMessages[] = $this->toolMessageForProvider($toolMsg);
            }

            if ($pendingWrites !== []) {
                return new MessageProcessedResult(
                    messages: [$userMessage],
                    pendingToolCalls: $pendingWrites,
                    streaming: $onChunk !== null,
                );
            }

            $providerMessages[] = $this->assistantToolCallsForProvider($aiResponse);
            foreach ($toolProviderMessages as $providerToolMessage) {
                $providerMessages[] = $providerToolMessage;
            }

            $aiResponse = $this->askProvider($this->withSystemPrompt($providerMessages), $toolsForUser, $onChunk);
        }

        $assistantMessage = Message::assistant(
            $command->conversationId,
            $aiResponse->content,
            $aiResponse->tokensUsed,
        );
        $this->messages->append($assistantMessage);

        return new MessageProcessedResult(messages: [$userMessage, $assistantMessage], streaming: $onChunk !== null);
    }

    private function executeConfirmedToolCalls(ActorContext $actor, SendMessageCommand $command, Conversation $conversation, ?callable $onChunk = null): MessageProcessedResult
    {
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
            $this->messages->append($this->buildToolMessage($command->conversationId, $tc, $result));
        }

        $history = $this->messages->findForConversation($command->conversationId, limit: 20);
        $aiResponse = $this->askProvider($this->withSystemPrompt($this->toProviderMessages($history)), $this->toolsForActor($actor), $onChunk);

        $assistantMessage = Message::assistant(
            $command->conversationId,
            $aiResponse->content,
            $aiResponse->tokensUsed,
        );
        $this->messages->append($assistantMessage);

        return new MessageProcessedResult(messages: [$assistantMessage], streaming: $onChunk !== null);
    }

    private function loadAccessibleConversation(ActorContext $actor, int $conversationId): Conversation
    {
        $conversation = $this->conversations->find($conversationId);
        if ($conversation === null) {
            throw ChatError::conversationAccessDenied();
        }

        if (! $actor->isSuperAdmin()) {
            if ($conversation->empresaId !== $actor->companyId()) {
                throw ChatError::conversationAccessDenied();
            }
            if ($conversation->usuarioId !== $actor->userId()) {
                throw ChatError::conversationAccessDenied();
            }
        }

        return $conversation;
    }

    private function buildToolMessage(int $conversationId, array $tc, ToolCallResult $result): Message
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

    /** @return array<int, \App\Domain\Chatbot\ToolDefinition> */
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
        return array_map(function (Message $message): array {
            if ($message->role === 'tool') {
                return $this->toolMessageForProvider($message);
            }

            return [
                'role' => $message->role,
                'content' => $message->content,
            ];
        }, $messages);
    }

    /**
     * Antepone el prompt de sistema. La API es stateless: si no lo enviamos
     * en cada llamada, el modelo "olvida" las reglas entre turnos.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function withSystemPrompt(array $messages): array
    {
        if ($messages !== [] && ($messages[0]['role'] ?? null) === 'system') {
            return $messages;
        }
        return array_merge(
            [['role' => 'system', 'content' => self::SYSTEM_PROMPT]],
            $messages,
        );
    }

    /** @return array<string, mixed> */
    private function assistantToolCallsForProvider(AIResponse $response): array
    {
        return [
            'role' => 'assistant',
            'content' => $response->content !== '' ? $response->content : null,
            'tool_calls' => array_map(static fn (array $tc): array => [
                'id' => (string) ($tc['id'] ?? ''),
                'type' => 'function',
                'function' => [
                    'name' => (string) ($tc['name'] ?? ''),
                    'arguments' => json_encode($tc['arguments'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ], $response->toolCalls),
        ];
    }

    /** @return array<string, mixed> */
    private function toolMessageForProvider(Message $message): array
    {
        $payload = $message->toolCalls ?? [];

        return [
            'role' => 'tool',
            'tool_call_id' => $message->toolCallId ?? '',
            'content' => json_encode([
                'name' => $payload['name'] ?? null,
                'success' => $payload['success'] ?? false,
                'result' => $payload['result'] ?? [],
                'error' => $payload['error'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $providerMessages
     * @param array<int, \App\Domain\Chatbot\ToolDefinition> $tools
     */
    private function askProvider(array $providerMessages, array $tools, ?callable $onChunk): AIResponse
    {
        return $onChunk === null
            ? $this->aiProvider->sendMessage($providerMessages, $tools)
            : $this->aiProvider->sendMessageStreaming($providerMessages, $tools, $onChunk);
    }
}
