<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Chatbot\Audit\ChatAuditAccess;
use App\Infrastructure\Chatbot\Persistence\CodeIgniterChatAuditReader;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

final class ChatbotAudit extends BaseController
{
    public function index(): ResponseInterface
    {
        try {
            $actor = $this->actor();
            $access = new ChatAuditAccess();
            $companyScope = $access->companyScope($actor);

            $filters = [
                'company_id' => $this->positiveInt($this->request->getGet('companyId')),
                'user_id' => $this->positiveInt($this->request->getGet('userId')),
                'date_from' => $this->date($this->request->getGet('dateFrom')),
                'date_to' => $this->date($this->request->getGet('dateTo')),
                'q' => trim((string) ($this->request->getGet('q') ?? '')),
            ];

            if ($filters['date_from'] !== null && $filters['date_to'] !== null
                && $filters['date_from'] > $filters['date_to']) {
                throw new DomainException('La fecha desde no puede ser posterior a la fecha hasta.');
            }

            $page = max(1, (int) ($this->request->getGet('page') ?? 1));
            $perPage = max(1, min(100, (int) ($this->request->getGet('perPage') ?? 25)));

            $reader = new CodeIgniterChatAuditReader(db_connect());
            $result = $reader->paginate($companyScope, $filters, $page, $perPage);

            return $this->response->setJSON([
                'data' => $result['items'],
                'pagination' => [
                    'page' => $result['page'],
                    'perPage' => $result['per_page'],
                    'total' => $result['total'],
                    'pages' => $result['pages'],
                ],
                'scope' => [
                    'type' => $companyScope === null ? 'global' : 'company',
                    'companyId' => $companyScope,
                ],
            ]);
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 403);
        } catch (Throwable $e) {
            log_message('error', 'Chatbot audit list error: ' . $e->getMessage());
            return $this->error('No se pudo consultar la auditoría de conversaciones.', 500);
        }
    }

    public function show(string $conversationId): ResponseInterface
    {
        try {
            $id = (int) $conversationId;
            if ($id <= 0) {
                return $this->error('Conversación inválida.', 404);
            }

            $actor = $this->actor();
            $companyScope = (new ChatAuditAccess())->companyScope($actor);
            $detail = (new CodeIgniterChatAuditReader(db_connect()))->detail($id, $companyScope);
            if ($detail === null) {
                // 404 evita confirmar la existencia de conversaciones fuera del tenant.
                return $this->error('Conversación no encontrada.', 404);
            }

            return $this->response->setJSON(['data' => $detail]);
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 403);
        } catch (Throwable $e) {
            log_message('error', 'Chatbot audit detail error: ' . $e->getMessage());
            return $this->error('No se pudo consultar la conversación.', 500);
        }
    }

    private function actor(): \App\Application\Identity\ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new DomainException('No existe un contexto autenticado válido.');
        }

        return $actor;
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($number === false) {
            throw new DomainException('Uno de los identificadores de filtro es inválido.');
        }

        return (int) $number;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string) $value;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new DomainException('La fecha debe tener formato YYYY-MM-DD.');
        }

        return $value;
    }

    private function error(string $message, int $status): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON(['error' => $message]);
    }
}
