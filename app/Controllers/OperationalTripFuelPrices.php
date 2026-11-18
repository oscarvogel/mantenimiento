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
            $entries = $this->entries();
            $saved = service('completeFuelPrices')->execute($this->actor(), $entries);

            return redirect()->to(self::PATH)->with('success', $saved === 1
                ? 'Se guardó el precio de 1 carga de combustible.'
                : sprintf('Se guardaron los precios de %d cargas de combustible.', $saved));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudieron guardar los precios del combustible.', self::PATH);
        }
    }

    /** @return list<FuelPriceEntry> */
    private function entries(): array
    {
        $amounts = $this->request->getPost('importe');
        $currencies = $this->request->getPost('moneda');
        $rates = $this->request->getPost('cotizacion');
        if (! is_array($amounts)) {
            return [];
        }

        $entries = [];
        foreach ($amounts as $fuelId => $rawAmount) {
            $id = filter_var($fuelId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $amount = str_replace(',', '.', trim((string) $rawAmount));
            if ($id === false || $amount === '') {
                // Fila vacía: esa carga queda pendiente para otro momento.
                continue;
            }
            if (! is_numeric($amount) || (float) $amount <= 0) {
                throw new DomainException('Los importes deben ser mayores que cero. Dejá vacío lo que todavía no tengas.');
            }
            $currency = strtoupper(trim((string) (is_array($currencies) ? ($currencies[$fuelId] ?? 'ARS') : 'ARS')));
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new DomainException('La moneda no es válida.');
            }
            $rate = str_replace(',', '.', trim((string) (is_array($rates) ? ($rates[$fuelId] ?? '') : '')));
            if ($currency === 'ARS' || $rate === '') {
                $rate = '1';
            }
            if (! is_numeric($rate) || (float) $rate <= 0) {
                throw new DomainException('La cotización debe ser mayor que cero.');
            }
            $entries[] = new FuelPriceEntry((int) $id, (float) $amount, $currency, (float) $rate);
        }

        return $entries;
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
