<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Trips\OperationalTripFilters;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

final class OperationalTripsDashboard extends BaseController
{
    public function index(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $filters = OperationalTripFilters::fromQuery($this->request->getGet());
            $payload = service('getOperationalTripsDashboard')->execute($actor, $filters);

            return $this->renderApp(
                $actor,
                'trips-operations-dashboard',
                'trips-operations-dashboard',
                'Tablero de viajes operativos',
                $payload,
            );
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new DomainException('Tu sesión no está disponible.');
        }

        return $actor;
    }

    private function failure(Throwable $exception): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló el tablero de viajes operativos: {message}', ['message' => $exception->getMessage()]);
        }

        return redirect()->to('/inicio')->with(
            'error',
            $exception instanceof DomainException
                ? $exception->getMessage()
                : 'No se pudo cargar el tablero de viajes operativos. Volvé a intentarlo.',
        );
    }
}
