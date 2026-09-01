<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Notifications\NotificationDispatchInProgress;
use App\Application\Notifications\Port\NotificationCronRateLimiter;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

final class NotificationCron extends BaseController
{
    public function dispatch(): ResponseInterface
    {
        return $this->dispatchWithToken($this->headerToken());
    }

    /** @deprecated Mantener hasta confirmar que ninguna tarea Ferozo usa la URL legacy. */
    public function legacyDispatch(string $token): ResponseInterface
    {
        log_message('warning', 'Se utilizó la ruta legacy deprecated del cron de notificaciones.');

        return $this->dispatchWithToken($token);
    }

    public function methodNotAllowed(): ResponseInterface
    {
        return $this->response
            ->setHeader('Allow', 'POST')
            ->setStatusCode(405)
            ->setJSON(['status' => 'error', 'error' => 'method_not_allowed']);
    }

    private function dispatchWithToken(?string $provided): ResponseInterface
    {
        if (! filter_var(env('alerts.webCronEnabled', false), FILTER_VALIDATE_BOOL)) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'error' => 'not_found']);
        }

        $rateLimiter = $this->rateLimiter();
        if (! $rateLimiter->allow($this->request->getIPAddress())) {
            return $this->response
                ->setHeader('Retry-After', (string) $rateLimiter->retryAfterSeconds())
                ->setStatusCode(429)
                ->setJSON(['status' => 'error', 'error' => 'rate_limited']);
        }

        $expected = trim((string) env('alerts.webCronToken', ''));
        $provided = trim((string) $provided);
        if ($provided === '') {
            log_message('warning', 'Intento rechazado de ejecución HTTP del cron de notificaciones.');

            return $this->response->setStatusCode(401)->setJSON(['status' => 'error', 'error' => 'unauthorized']);
        }

        if (strlen($expected) < 32 || ! hash_equals($expected, $provided)) {
            log_message('warning', 'Intento rechazado de ejecución HTTP del cron de notificaciones.');

            return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'error' => 'forbidden']);
        }

        try {
            $summary = $this->technicalSummary(
                service('notificationCycle')->execute(null, (int) env('alerts.lockTimeoutSeconds', 900)),
            );
            log_message('notice', 'Cron HTTP de notificaciones ejecutado: {summary}', [
                'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return $this->response->setJSON($summary);
        } catch (NotificationDispatchInProgress) {
            return $this->response->setStatusCode(409)->setJSON([
                'status' => 'error',
                'error' => 'notification_dispatch_in_progress',
            ]);
        } catch (Throwable) {
            log_message('error', 'Falló cron HTTP de notificaciones.');

            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'error' => 'notification_dispatch_failed',
            ]);
        }
    }

    public function manual(): RedirectResponse
    {
        try {
            $result = service('notificationCycle')->execute(null, (int) env('alerts.lockTimeoutSeconds', 900));
            $dispatched = $result['dispatched'];
            $message = sprintf(
                'Ciclo ejecutado. Eventos: %d; emails: %d; emails empresa: %d; push: %d; fallas: %d.',
                (int) ($result['collected']['events'] ?? 0),
                (int) ($dispatched['email_sent'] ?? 0),
                (int) ($dispatched['company_email_sent'] ?? 0),
                (int) ($dispatched['push_sent'] ?? 0),
                (int) ($dispatched['failed'] ?? 0),
            );

            return redirect()->to('/superadmin')->with('success', $message);
        } catch (Throwable) {
            log_message('error', 'Falló ejecución manual de notificaciones.');

            return redirect()->to('/superadmin')->with('error', 'No se pudo ejecutar el ciclo de notificaciones. Revisá el log y la configuración de canales.');
        }
    }

    private function headerToken(): ?string
    {
        $token = trim($this->request->getHeaderLine('X-Cron-Token'));
        if ($token !== '') {
            return $token;
        }

        $authorization = trim($this->request->getHeaderLine('Authorization'));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private function rateLimiter(): NotificationCronRateLimiter
    {
        return service('notificationCronRateLimiter');
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function technicalSummary(array $result): array
    {
        $overdue = is_array($result['overdue'] ?? null) ? $result['overdue'] : [];
        $collected = is_array($result['collected'] ?? null) ? $result['collected'] : [];
        $dispatched = is_array($result['dispatched'] ?? null) ? $result['dispatched'] : [];

        return [
            'status' => 'ok',
            'execution_key' => (string) ($result['execution_key'] ?? ''),
            'overdue' => [
                'companies' => (int) ($overdue['companies'] ?? 0),
                'evaluated' => (int) ($overdue['evaluated'] ?? 0),
                'overdue' => (int) ($overdue['overdue'] ?? 0),
            ],
            'collected' => [
                'events' => (int) ($collected['events'] ?? 0),
                'created' => (int) ($collected['created'] ?? 0),
                'duplicates' => (int) ($collected['duplicates'] ?? 0),
            ],
            'sent' => (int) ($dispatched['email_sent'] ?? 0)
                + (int) ($dispatched['company_email_sent'] ?? 0)
                + (int) ($dispatched['push_sent'] ?? 0),
            'retry' => (int) ($dispatched['retry'] ?? 0),
            'skipped' => (int) ($dispatched['skipped'] ?? 0),
            'errors' => (int) ($dispatched['failed'] ?? 0),
            'already_completed' => (int) ($dispatched['already_completed'] ?? 0),
        ];
    }
}
