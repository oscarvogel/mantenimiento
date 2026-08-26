<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Notifications\RunNotificationCycle;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

final class NotificationCron extends BaseController
{
    public function dispatch(string $token): ResponseInterface
    {
        if (! filter_var(env('alerts.webCronEnabled', false), FILTER_VALIDATE_BOOL)) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false, 'error' => 'not_found']);
        }

        $expected = trim((string) env('alerts.webCronToken', ''));
        if (strlen($expected) < 32 || ! hash_equals($expected, $token)) {
            log_message('warning', 'Intento rechazado de ejecución HTTP del cron de notificaciones.');

            return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'error' => 'unauthorized']);
        }

        try {
            $result = $this->cycle()->execute(null, (int) env('alerts.lockTimeoutSeconds', 900));
            log_message('notice', 'Cron HTTP de notificaciones ejecutado: {summary}', [
                'summary' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return $this->response->setJSON(['ok' => true, 'result' => $result]);
        } catch (Throwable $exception) {
            log_message('error', 'Falló cron HTTP de notificaciones: {message}', ['message' => $exception->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'error' => 'notification_dispatch_failed',
            ]);
        }
    }

    public function manual(): RedirectResponse
    {
        try {
            $result = $this->cycle()->execute(null, (int) env('alerts.lockTimeoutSeconds', 900));
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
        } catch (Throwable $exception) {
            log_message('error', 'Falló ejecución manual de notificaciones: {message}', ['message' => $exception->getMessage()]);

            return redirect()->to('/superadmin')->with('error', 'No se pudo ejecutar el ciclo de notificaciones. Revisá el log y la configuración de canales.');
        }
    }

    private function cycle(): RunNotificationCycle
    {
        return new RunNotificationCycle(
            service('detectOverduePlansAutomatically'),
            service('operationalNotificationCollector'),
            service('notificationDispatch'),
            service('notificationClock'),
        );
    }
}
