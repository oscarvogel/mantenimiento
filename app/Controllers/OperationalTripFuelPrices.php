<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Trips\FuelPriceEntry;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

/**
 * «Combustible sin precio»: cargas registradas sólo con litros, para poner los
 * importes de una pasada cuando llega el precio.
 */
final class OperationalTripFuelPrices extends BaseController
{
    private const PATH = '/viajes/operativos/combustible-sin-precio';

    public function index(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $payload = service('listPendingFuelPrices')->execute($actor);

            return $this->renderApp($actor, 'trips-fuel-pending', 'trips-fuel-pending', 'Combustible sin precio', $payload);
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo cargar el combustible sin precio.', '/viajes/operativos');
        }
    }

    public function save(): RedirectResponse
    {
        try {
            $entries = FuelPriceEntry::fromPost((array) $this->request->getPost());
            $saved = service('completeFuelPrices')->execute($this->actor(), $entries);

            return redirect()->to(self::PATH)->with('success', $saved === 1
                ? 'Se guardó el precio de 1 carga de combustible.'
                : sprintf('Se guardaron los precios de %d cargas de combustible.', $saved));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudieron guardar los precios del combustible.', self::PATH);
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

    private function failure(Throwable $exception, string $fallback, string $to): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló combustible sin precio: {message}', ['message' => $exception->getMessage()]);
        }

        return redirect()->to($to)->withInput()->with('error', $exception instanceof DomainException ? $exception->getMessage() : $fallback);
    }
}
