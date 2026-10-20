<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Trips\TripProfitabilityFilters;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

final class TripProfitability extends BaseController
{
    public function index(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $filters = TripProfitabilityFilters::fromQuery($this->request->getGet());
            $requestedPage = filter_var($this->request->getGet('pagina'), FILTER_VALIDATE_INT);
            $page = $requestedPage === false || $requestedPage === null ? 1 : $requestedPage;
            $payload = service('getTripProfitability')->execute($actor, $filters, $page);

            return $this->renderApp($actor, 'trips-profitability', 'trip-profitability', 'Rentabilidad de viajes', $payload);
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'Falló el análisis de rentabilidad de viajes ({exceptionClass}).', [
                    'exceptionClass' => $exception::class,
                ]);
            }

            return redirect()->to('/viajes')->with(
                'error',
                $exception instanceof DomainException
                    ? $exception->getMessage()
                    : 'No se pudo cargar el análisis de rentabilidad. Volvé a intentarlo.',
            );
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
}
