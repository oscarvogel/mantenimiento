<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\GetNotificationCenter;
use App\Application\Notifications\ManageNotificationPreferences;
use App\Application\Notifications\ManageWebPushSubscriptions;
use App\Application\Notifications\MarkNotificationRead;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

final class Notifications extends BaseController
{
    public function index(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $page = service('notificationCenter')->execute($actor, max(1, (int) $this->request->getGet('page')), (int) $this->request->getGet('per_page'));
            $preferences = service('notificationPreferences')->list($actor);

            return $this->renderApp($actor, 'notifications', 'notifications', 'Notificaciones', [
                'notifications' => $this->pageData($page),
                'preferences' => array_map(static fn ($preference): array => [
                    'internal' => $preference->internal->value,
                    'email' => $preference->email->value,
                    'push' => $preference->push->value,
                ], $preferences),
                'push' => [
                    'enabled' => filter_var(env('webpush.enabled', false), FILTER_VALIDATE_BOOL),
                    'publicKey' => (string) env('webpush.vapidPublicKey', ''),
                ],
                'urls' => [
                    'index' => base_url('notificaciones'),
                    'read' => base_url('notificaciones/leer'),
                    'readAll' => base_url('notificaciones/leer-todas'),
                    'preferences' => base_url('perfil/notificaciones'),
                    'subscribe' => base_url('perfil/notificaciones/webpush'),
                    'unsubscribe' => base_url('perfil/notificaciones/webpush/eliminar'),
                    'test' => base_url('perfil/notificaciones/webpush/prueba'),
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function summary(): ResponseInterface
    {
        try {
            $page = service('notificationCenter')->execute($this->actor(), 1, 5);
            return $this->response->setJSON(['unread' => $page->unread, 'items' => $page->items]);
        } catch (Throwable) {
            return $this->response->setStatusCode(403)->setJSON(['unread' => 0, 'items' => []]);
        }
    }

    public function read(int $id): RedirectResponse
    {
        try {
            service('markNotificationRead')->one($this->actor(), $id);
            return redirect()->to('/notificaciones')->with('success', 'Notificación marcada como leída.');
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function readAll(): RedirectResponse
    {
        try {
            $count = service('markNotificationRead')->all($this->actor());
            return redirect()->to('/notificaciones')->with('success', "Se marcaron {$count} notificaciones como leídas.");
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function updatePreferences(): RedirectResponse
    {
        try {
            service('notificationPreferences')->update(
                $this->actor(),
                trim((string) $this->request->getPost('event_type')),
                (string) $this->request->getPost('internal'),
                (string) $this->request->getPost('email'),
                (string) $this->request->getPost('push'),
            );
            return redirect()->to('/notificaciones')->with('success', 'Preferencia actualizada.');
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function subscribe(): ResponseInterface
    {
        try {
            $json = $this->request->getJSON(true);
            if (! is_array($json)) {
                throw new DomainException('El cuerpo de la suscripción no es válido.');
            }
            $keys = is_array($json['keys'] ?? null) ? $json['keys'] : [];
            $id = service('webPushSubscriptions')->subscribe(
                $this->actor(),
                (string) ($json['endpoint'] ?? ''),
                (string) ($keys['p256dh'] ?? ''),
                (string) ($keys['auth'] ?? ''),
                isset($json['deviceName']) ? trim((string) $json['deviceName']) : null,
                $this->request->getUserAgent()->getAgentString(),
            );
            return $this->response->setStatusCode(201)->setJSON(['id' => $id, 'csrf' => $this->csrfData()]);
        } catch (Throwable $exception) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $this->message($exception), 'csrf' => $this->csrfData()]);
        }
    }

    public function unsubscribe(): ResponseInterface
    {
        try {
            $json = $this->request->getJSON(true);
            service('webPushSubscriptions')->unsubscribe($this->actor(), is_array($json) ? (string) ($json['endpoint'] ?? '') : '');
            return $this->response->setJSON(['ok' => true, 'csrf' => $this->csrfData()]);
        } catch (Throwable $exception) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $this->message($exception), 'csrf' => $this->csrfData()]);
        }
    }

    public function testPush(): ResponseInterface
    {
        try {
            return $this->response->setJSON(service('webPushTest')->execute($this->actor()) + ['csrf' => $this->csrfData()]);
        } catch (Throwable $exception) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $this->message($exception), 'csrf' => $this->csrfData()]);
        }
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new DomainException('No existe un contexto autenticado válido.');
        }
        return $actor;
    }

    private function failure(Throwable $exception): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló el centro de notificaciones: {message}', ['message' => $exception->getMessage()]);
        }
        return redirect()->to('/notificaciones')->with('error', $this->message($exception));
    }

    private function message(Throwable $exception): string
    {
        return $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo completar la operación de notificaciones.';
    }

    /** @return array<string,mixed> */
    private function pageData(object $page): array
    {
        $totalPages = max(1, (int) ceil($page->total / max(1, $page->perPage)));
        $url = static fn (int $number): string => base_url('notificaciones') . '?' . http_build_query(['page' => $number, 'per_page' => $page->perPage]);
        return [
            'items' => $page->items, 'unread' => $page->unread, 'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total,
            'pagination' => [
                'page' => $page->page, 'perPage' => $page->perPage, 'total' => $page->total, 'totalPages' => $totalPages,
                'previousUrl' => $page->page > 1 ? $url($page->page - 1) : null,
                'nextUrl' => $page->page < $totalPages ? $url($page->page + 1) : null,
                'perPageOptions' => [5, 10, 25], 'pageKey' => 'page', 'perPageKey' => 'per_page',
            ],
        ];
    }

    /** @return array{name:string,hash:string} */
    private function csrfData(): array
    {
        return ['name' => csrf_token(), 'hash' => csrf_hash()];
    }
}
