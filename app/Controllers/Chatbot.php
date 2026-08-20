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

            return $this->jsonOk(['messages' => $messages]);
        } catch (Throwable $e) {
            return $this->jsonError($e);
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

            return $this->jsonOk(['messages' => $data]);
        } catch (Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function sendMessageStream(): ResponseInterface
    {
        $this->response->setHeader('Content-Type', 'text/event-stream')
            ->setHeader('Cache-Control', 'no-cache')
            ->setHeader('Connection', 'keep-alive');

        $actor = $this->actor();
        $conversationId = (int) $this->request->getPost('conversationId');
        $content = (string) ($this->request->getPost('content') ?? '');

        $sse = new \App\Infrastructure\Chatbot\SSE\StreamingResponse($this->response);

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
                $sse->sendToolCall($result->pendingToolCalls);
            }

            $sse->sendDone();
        } catch (Throwable $e) {
            $sse->sendError($e->getMessage());
        }

        return $this->response;
    }
}
