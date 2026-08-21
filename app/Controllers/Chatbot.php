<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Chatbot\Command\SendMessageCommand;
use App\Application\Chatbot\Command\StartConversationCommand;
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

    private function jsonError(Throwable $e, int $status = 422): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'error' => $e->getMessage(),
            'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }

    private function jsonOk(array $data): ResponseInterface
    {
        return $this->response->setJSON($data + [
            'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
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

            return $this->jsonOk([
                'conversation' => [
                    'id' => $result->conversation->id,
                    'empresaId' => $result->conversation->empresaId,
                    'titulo' => $result->conversation->titulo,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function sendMessage(): ResponseInterface
    {
        try {
            $actor = $this->actor();
            $conversationId = (int) $this->request->getPost('conversationId');
            $content = (string) ($this->request->getPost('content') ?? '');
            $confirmedRaw = $this->request->getPost('confirmedToolCalls');
            $confirmedToolCalls = is_string($confirmedRaw) ? json_decode($confirmedRaw, true) : null;

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

            return $this->jsonOk([
                'messages' => $messages,
                'pendingToolCalls' => $result->pendingToolCalls,
            ]);
        } catch (ChatError $e) {
            return $this->jsonError($e);
        } catch (Throwable $e) {
            log_message('error', 'Chatbot error: ' . $e->getMessage());
            return $this->jsonError(new \RuntimeException('Error interno del asistente.'), 500);
        }
    }

    public function confirmTool(): ResponseInterface
    {
        try {
            $actor = $this->actor();
            $conversationId = (int) $this->request->getPost('conversationId');
            $toolCalls = json_decode((string) ($this->request->getPost('toolCalls') ?? '[]'), true);
            if (! is_array($toolCalls)) {
                $toolCalls = [];
            }

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
                'toolCalls' => $m->toolCalls,
                'toolCallId' => $m->toolCallId,
            ], $result->messages);

            return $this->jsonOk(['messages' => $messages]);
        } catch (Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function history(): ResponseInterface
    {
        try {
            $actor = $this->actor();
            $conversationId = (int) $this->request->getGet('conversationId');
            $offset = (int) ($this->request->getGet('offset') ?? 0);
            $limit = (int) ($this->request->getGet('limit') ?? 50);

            $database = db_connect();
            $convRepo = new \App\Infrastructure\Chatbot\Persistence\CodeIgniterConversationRepository($database);

            $companyId = $actor->companyId();
            if ($companyId === null) {
                return $this->jsonError(new \DomainException('Sin empresa asociada.'), 422);
            }

            if ($actor->isSuperAdmin()) {
                $conversation = $convRepo->find($conversationId);
            } else {
                $conversation = $convRepo->findOwned($conversationId, $actor->userId(), $companyId);
            }
            if ($conversation === null) {
                return $this->jsonError(\App\Domain\Chatbot\ChatError::conversationAccessDenied(), 404);
            }

            $msgRepo = new \App\Infrastructure\Chatbot\Persistence\CodeIgniterMessageRepository($database);
            $messages = $msgRepo->findForConversation($conversationId, $limit, $offset);

            $data = array_map(fn($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'toolCalls' => $m->toolCalls,
                'createdAt' => $m->createdAt->format('c'),
            ], $messages);

            return $this->jsonOk(['messages' => $data]);
        } catch (Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function sendMessageStream(): void
    {
        $sse = new \App\Infrastructure\Chatbot\SSE\StreamingResponse($this->response);
        $sse->sendHeaders();

        $actor = $this->safeActor();
        if ($actor === null) {
            $sse->sendError('Sesión inválida o expirada.');
            $sse->sendDone();
            return;
        }

        $conversationId = (int) $this->request->getPost('conversationId');
        $content = (string) ($this->request->getPost('content') ?? '');
        $toolCallsRaw = $this->request->getPost('toolCalls');
        $confirmedToolCalls = is_string($toolCallsRaw) ? json_decode($toolCallsRaw, true) : null;
        if (! is_array($confirmedToolCalls)) {
            $confirmedToolCalls = null;
        }

        ignore_user_abort(false);
        @set_time_limit(0);

        try {
            $handler = service('processMessage');
            $result = $handler->execute(
                $actor,
                new SendMessageCommand(
                    conversationId: $conversationId,
                    content: $content,
                    confirmedToolCalls: $confirmedToolCalls,
                ),
                static function (string $chunk) use ($sse): void {
                    $sse->sendChunk($chunk);
                },
            );

            if ($result->pendingToolCalls !== []) {
                $sse->sendPendingTools($result->pendingToolCalls);
            }

            $sse->sendDone();
        } catch (ChatError $e) {
            $sse->sendError($e->getMessage());
            $sse->sendDone();
        } catch (Throwable $e) {
            log_message('error', 'Chatbot SSE error: ' . $e->getMessage());
            $sse->sendError('Error interno del asistente.');
            $sse->sendDone();
        }
    }

    private function safeActor(): ?\App\Application\Identity\ActorContext
    {
        try {
            return $this->actor();
        } catch (\DomainException) {
            $this->response->setStatusCode(401);
            return null;
        }
    }
}
