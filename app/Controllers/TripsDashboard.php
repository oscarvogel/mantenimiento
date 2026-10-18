<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Trips\TripDashboardFilters;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

final class TripsDashboard extends BaseController
{
    public function index(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $filters = TripDashboardFilters::fromQuery($this->request->getGet());

            return $this->renderApp(
                $actor,
                'trips-dashboard',
                'trips-dashboard',
                'Viajes',
                service('getTripsDashboard')->execute($actor, $filters),
            );
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new DomainException('Tu sesion no esta disponible.');
        }

        return $actor;
    }

    private function failure(Throwable $exception): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Fallo el dashboard de viajes ({exceptionClass}).', [
                'exceptionClass' => $exception::class,
            ]);
        }

        return redirect()->to('/inicio')->with(
            'error',
            $exception instanceof DomainException
                ? $exception->getMessage()
                : 'No se pudo cargar el dashboard de viajes. Volve a intentarlo.',
        );
    }
}
