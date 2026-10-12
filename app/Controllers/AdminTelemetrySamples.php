<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

/** Read-only raw sample inspector available to global administrators. */
final class AdminTelemetrySamples extends BaseController
{
    public function index(): string|RedirectResponse
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || ! $actor->isSuperAdmin()) {
            return redirect()->to(base_url('login'));
        }

        try {
            $data = service('adminTelemetrySamples')->execute($this->request->getGet());
            return $this->renderApp(
                $actor,
                'telemetry-samples',
                'telemetry-samples-admin',
                'Muestras de telemetría',
                $data,
            );
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'No se pudo cargar el inspector de muestras de telemetría.');
            }

            return redirect()->to(base_url('superadmin'))->with(
                'error',
                $exception instanceof DomainException
                    ? $exception->getMessage()
                    : 'No se pudieron consultar las muestras de telemetría.',
            );
        }
    }
}
