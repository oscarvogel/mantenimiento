<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\GetAutomationOverview;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

final class Automations extends BaseController
{
    public function index(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $overview = $this->handler()->execute($actor);

            return $this->renderApp(
                $actor,
                'automations',
                'automations',
                'Automatizaciones',
                [
                    'overview' => $overview,
                    'portalUrl' => base_url('inicio'),
                ],
            );
        } catch (Throwable $exception) {
            return $this->failure($exception);
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

    private function handler(): GetAutomationOverview
    {
        return service('automationOverview');
    }

    private function failure(Throwable $exception): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló el seguimiento de automatizaciones: {message}', ['message' => $exception->getMessage()]);
        }

        return redirect()->to('/inicio')->with(
            'error',
            $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo cargar el seguimiento de automatizaciones.',
        );
    }
}
