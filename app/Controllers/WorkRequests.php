<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\WorkRequests\ListWorkRequests;
use App\Application\WorkRequests\ReviewWorkRequest;
use App\Infrastructure\Identity\SessionActorContext;
use App\Infrastructure\WorkRequests\CodeIgniterWorkRequestRepository;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

final class WorkRequests extends BaseController
{
    public function index(): string
    {
        $actor = $this->actor();
        $filters = [
            'q' => trim((string) $this->request->getGet('q')),
            'status' => mb_strtoupper(trim((string) $this->request->getGet('estado'))),
        ];
        $page = max(1, (int) ($this->request->getGet('page') ?: 1));
        $perPage = (int) ($this->request->getGet('per_page') ?: 25);
        $data = $this->repositoryList()->execute($actor, $filters, $page, $perPage);
        $base = base_url('mantenimiento/solicitudes');

        $data['filters'] = $filters;
        $data['canReview'] = $actor->hasPermission('solicitudes.revisar');
        $data['routes'] = ['index' => $base];
        $data['csrf'] = ['name' => csrf_token(), 'hash' => csrf_hash()];
        $data['pagination']['previousUrl'] = $data['page'] > 1 ? $this->pageUrl($base, $filters, $data['page'] - 1, $data['perPage']) : null;
        $data['pagination']['nextUrl'] = $data['page'] < $data['totalPages'] ? $this->pageUrl($base, $filters, $data['page'] + 1, $data['perPage']) : null;

        return $this->renderApp($actor, 'work-requests', 'work-requests-index', 'Solicitudes de mantenimiento', $data);
    }

    public function review(int $requestId): RedirectResponse
    {
        try {
            $groupId = trim((string) $this->request->getPost('agrupada_en_id'));
            $this->repositoryReview()->execute(
                $this->actor(),
                $requestId,
                (string) $this->request->getPost('estado'),
                (string) $this->request->getPost('motivo'),
                $groupId === '' ? null : (int) $groupId,
            );
            return redirect()->to('/mantenimiento/solicitudes')->with('success', 'La solicitud fue actualizada.');
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) log_message('error', 'Falló la revisión de solicitud: {message}', ['message' => $exception->getMessage()]);
            return redirect()->to('/mantenimiento/solicitudes')->withInput()->with('error', $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo actualizar la solicitud.');
        }
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || $actor->companyId() === null) throw new DomainException('No existe un contexto autenticado válido.');
        return $actor;
    }

    private function repositoryList(): ListWorkRequests { return new ListWorkRequests(new CodeIgniterWorkRequestRepository(db_connect())); }
    private function repositoryReview(): ReviewWorkRequest { return new ReviewWorkRequest(new CodeIgniterWorkRequestRepository(db_connect())); }

    private function pageUrl(string $base, array $filters, int $page, int $perPage): string
    {
        return $base . '?' . http_build_query(array_filter([
            'q' => $filters['q'] ?? '', 'estado' => $filters['status'] ?? '', 'page' => $page, 'per_page' => $perPage,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }
}
