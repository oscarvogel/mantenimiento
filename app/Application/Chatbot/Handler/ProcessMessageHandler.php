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
use App\Application\Chatbot\Support\MarkdownTextCleaner;
use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\ChatError;
use App\Domain\Chatbot\Conversation;
use App\Domain\Chatbot\Message;
use App\Domain\Chatbot\ToolCallResult;

final class ProcessMessageHandler
{
    private const MAX_TOOL_ROUNDS = 4;

    private const SYSTEM_PROMPT = <<<'TXT'
Sos el asistente virtual del sistema de Gestión de Mantenimiento (Vogel Consultoría).
Tu alcance está estrictamente limitado a este sistema y sus datos.

ALCANCE (respondes solo sobre estos temas):
- Equipos / flota: busqueda individual por código, patente o chasis; listados por estado de plan.
- Planes preventivos: estado (AL_DIA, PROXIMO, VENCIDO, SIN_DATOS), intervalos, próximas mantenciones, kilometraje, horas, fechas.
- Lecturas (carga, corrección, regularizaciones) y ordenes de trabajo.
- Catálogo de servicios de mantenimiento y catálogo de tareas.
- Sucursales y usuarios del sistema (dentro del alcance del usuario actual).

REGLAS DE TOOLS (selección inequívoca - OBLIGATORIO):
- Preguntas sobre OT abiertas/pendientes/en proceso/cerradas → usar listar_ordenes_trabajo o consultar_orden_trabajo, NUNCA planes. Ej: "qué OT tengo abierta" → listar_ordenes_trabajo.
- Preguntas sobre kilometraje/horas actuales o última lectura → OBLIGATORIO usar consultar_equipo o consultar_ultima_lectura, NUNCA planes. Si el usuario da código/patente/chasis y todavía no hay equipment_id, resolver primero con buscar_equipo.
- Si un equipo ya fue resuelto y luego el usuario dice "el camión", "el equipo" o "cuantos km tiene el camion" SIN nuevo código, REUTILIZAR OBLIGATORIAMENTE ese equipment_id con consultar_ultima_lectura, NUNCA inventar otro id ni volver a buscar.
- Si el usuario escribe EXPLÍCITAMENTE un nuevo código, patente o chasis, DEBES llamar INMEDIATAMENTE a buscar_equipo con ese valor, sin pedir confirmación y sin reutilizar el equipo anterior.
- Si buscar_equipo devuelve exact_match=false e items vacío, NO usar ninguna sugerencia como si fuera el equipo correcto. Informar que no hubo coincidencia exacta y, si hay suggestions, ofrecerlas para confirmación del usuario.
- NUNCA inventes kilometraje, horas, fechas, IDs ni entidades; si una herramienta no devuelve el dato, dilo. Herramientas de planes SOLO para consultas preventivas (vencido, próximo, plan, mantenimiento programado).

REGLAS DE LINKS (OBLIGATORIO):
- Nunca construyas, completes, corrijas, combines ni infieras URLs.
- Solamente podés mostrar una URL cuando esté presente literalmente dentro del campo links devuelto por una herramienta.
- Copiá esa URL exactamente, sin prefijarla, concatenarla ni cambiar dominio, protocolo, puerto, path o query string.
- Si la herramienta no devuelve un link, no inventes uno.

FORMATO DE LAS RESPUESTAS:
- Responde siempre en español rioplatense (Argentina), en forma breve y profesional.
- Escribi SIEMPRE en prosa con bullets (listas con "- ..."). NO uses tablas markdown.
- Para listar equipos/resultados, una linea corta por item, sin alineacion ni columnas.
- Separa los datos del sistema con espacios y unidades (ej. "121.250 km", "10 dias", "4975 horas"), no agrupes en columnas.
- Si una herramienta devuelve URLs útiles en links, podés mostrarlas en una línea aparte como link Markdown usando exactamente esa URL.
- Cierra ofreciendo el siguiente paso cuando aplique, en una sola oracion.
- Cuando uses datos del sistema, mencionalos explicitamente; nunca inventes valores.
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

        $deterministic = $this->tryHandleExplicitMeasurementLookup($actor, $command, $userMessage);
        if ($deterministic !== null) {
            return $deterministic;
        }

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
            MarkdownTextCleaner::normalize($aiResponse->content),
            $aiResponse->tokensUsed,
        );
        $this->messages->append($assistantMessage);

        return new MessageProcessedResult(messages: [$userMessage, $assistantMessage], streaming: $onChunk !== null);
    }

    private function tryHandleExplicitMeasurementLookup(
        ActorContext $actor,
        SendMessageCommand $command,
        Message $userMessage,
    ): ?MessageProcessedResult {
        $identifier = $this->extractExplicitIdentifierForMeasurement($command->content);
        if ($identifier === null || ! $actor->hasPermission('equipos.ver')) {
            return null;
        }

        $searchCall = [
            'id' => 'det_search_' . uniqid(),
            'name' => 'buscar_equipo',
            'arguments' => ['query' => $identifier],
        ];
        $searchResult = $this->toolExecutor->execute('buscar_equipo', $searchCall['arguments'], $actor);
        $this->messages->append($this->buildToolMessage($command->conversationId, $searchCall, $searchResult));

        if (! $searchResult->success) {
            return $this->finishDeterministic($command->conversationId, $userMessage, $searchResult->errorMessage ?? 'No pude buscar el equipo.');
        }

        $payload = is_array($searchResult->result) ? $searchResult->result : [];
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $exactMatch = ($payload['exact_match'] ?? null) === true;

        if (! $exactMatch || count($items) !== 1) {
            $text = 'No encontré un equipo con código, patente o chasis **' . $identifier . '**.';
            $suggestions = is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [];
            if ($suggestions !== []) {
                $labels = [];
                foreach (array_slice($suggestions, 0, 3) as $suggestion) {
                    if (! is_array($suggestion)) {
                        continue;
                    }
                    $code = trim((string) ($suggestion['codigo'] ?? ''));
                    $plate = trim((string) ($suggestion['patente'] ?? ''));
                    $labels[] = trim($code . ($plate !== '' ? ' (' . $plate . ')' : ''));
                }
                if ($labels !== []) {
                    $text .= ' Encontré como posibles coincidencias: ' . implode(', ', $labels) . '. Confirmame cuál querés consultar.';
                }
            }
            return $this->finishDeterministic($command->conversationId, $userMessage, $text);
        }

        $equipment = $items[0];
        $equipmentId = (int) ($equipment['id'] ?? 0);
        if ($equipmentId <= 0) {
            return $this->finishDeterministic($command->conversationId, $userMessage, 'Encontré el equipo, pero no pude resolver su identificador interno.');
        }

        $toolName = $actor->hasPermission('lecturas.ver') ? 'consultar_ultima_lectura' : 'consultar_equipo';
        $metricCall = [
            'id' => 'det_metric_' . uniqid(),
            'name' => $toolName,
            'arguments' => ['equipment_id' => $equipmentId],
        ];
        $metricResult = $this->toolExecutor->execute($toolName, $metricCall['arguments'], $actor);
        $this->messages->append($this->buildToolMessage($command->conversationId, $metricCall, $metricResult));

        if (! $metricResult->success) {
            return $this->finishDeterministic($command->conversationId, $userMessage, $metricResult->errorMessage ?? 'No pude consultar la lectura del equipo.');
        }

        $result = is_array($metricResult->result) ? $metricResult->result : [];
        $code = trim((string) ($equipment['codigo'] ?? $result['code'] ?? $identifier));
        $plate = trim((string) ($equipment['patente'] ?? $result['plate'] ?? ''));
        $title = $code !== '' ? $code : $identifier;
        if ($plate !== '' && strcasecmp($plate, $title) !== 0) {
            $title .= ' (' . $plate . ')';
        }

        $km = null;
        $hours = null;
        $recordedAt = null;
        if ($toolName === 'consultar_ultima_lectura') {
            $reading = is_array($result['reading'] ?? null) ? $result['reading'] : [];
            $km = $reading['kilometers'] ?? null;
            $hours = $reading['hours'] ?? null;
            $recordedAt = $reading['recorded_at'] ?? null;
        } else {
            $km = $result['current_km'] ?? null;
            $hours = $result['current_hours'] ?? null;
        }

        $parts = [];
        if ($km !== null && $km !== '') {
            $parts[] = number_format((float) $km, 0, ',', '.') . ' km';
        }
        if ($hours !== null && $hours !== '') {
            $formattedHours = rtrim(rtrim(number_format((float) $hours, 1, ',', '.'), '0'), ',');
            $parts[] = $formattedHours . ' horas';
        }

        $text = $parts === []
            ? 'El equipo **' . $title . '** no tiene una lectura vigente de kilómetros u horas.'
            : 'El equipo **' . $title . '** registra **' . implode('** y **', $parts) . '**.';

        if ($recordedAt !== null && $recordedAt !== '') {
            $text .= ' Última lectura: ' . $recordedAt . '.';
        }

        $links = is_array($result['links'] ?? null) ? $result['links'] : [];
        $detail = trim((string) ($links['detail'] ?? ''));
        if ($detail !== '') {
            $text .= "\n\n[Ver equipo](" . $detail . ')';
        }

        return $this->finishDeterministic($command->conversationId, $userMessage, $text);
    }

    private function extractExplicitIdentifierForMeasurement(string $content): ?string
    {
        $normalized = mb_strtolower(trim($content), 'UTF-8');
        if (preg_match('/\b(km|kilometraje|kilómetros?|kilometros?|horas?|horómetro|horometro|lectura)\b/u', $normalized) !== 1) {
            return null;
        }

        if (preg_match_all('/\b[A-Za-z0-9][A-Za-z0-9._-]{3,}\b/', $content, $matches) !== 1 && empty($matches[0])) {
            return null;
        }

        $stop = ['cuantos', 'cuantas', 'tiene', 'movil', 'móvil', 'camion', 'camión', 'equipo', 'lectura', 'ultima', 'última', 'horas', 'hora', 'kilometraje'];
        foreach (array_reverse($matches[0] ?? []) as $candidate) {
            if (in_array(mb_strtolower($candidate, 'UTF-8'), $stop, true)) {
                continue;
            }
            if (preg_match('/[0-9]/', $candidate) === 1) {
                return strtoupper($candidate);
            }
        }

        return null;
    }

    private function finishDeterministic(int $conversationId, Message $userMessage, string $content): MessageProcessedResult
    {
        $assistant = Message::assistant($conversationId, MarkdownTextCleaner::normalize($content));
        $this->messages->append($assistant);

        return new MessageProcessedResult(messages: [$userMessage, $assistant], streaming: false);
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
            MarkdownTextCleaner::normalize($aiResponse->content),
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

    private function toolsForActor(ActorContext $actor): array
    {
        return array_values(array_filter(
            $this->toolRegistry->all(),
            fn ($tool) => $actor->hasPermission($tool->permission),
        ));
    }

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

    private function askProvider(array $providerMessages, array $tools, ?callable $onChunk): AIResponse
    {
        return $onChunk === null
            ? $this->aiProvider->sendMessage($providerMessages, $tools)
            : $this->aiProvider->sendMessageStreaming($providerMessages, $tools, $onChunk);
    }
}
