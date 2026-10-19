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
            $requestedPage = filter_var($this->request->getGet('pagina'), FILTER_VALIDATE_INT);
            $page = $requestedPage === false || $requestedPage === null ? 1 : $requestedPage;
            $dashboard = service('getTripsDashboard')->execute($actor, $filters, $page);
            $dashboard['links'] = ['index' => base_url('viajes')];

            return $this->renderApp(
                $actor,
                'trips-dashboard',
                'trips-dashboard',
                'Viajes',
                $dashboard,
            );
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function detail(int $tripId): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $detail = service('getTripDetail')->execute($actor, $tripId);
            $query = array_intersect_key($this->request->getGet(), array_flip(['desde', 'hasta', 'sucursal', 'dominio', 'pagina']));
            $detail['backUrl'] = base_url('viajes') . ($query === [] ? '' : '?' . http_build_query($query));

            return $this->renderApp($actor, 'trips-dashboard', 'trip-detail', 'Detalle del viaje', $detail);
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
