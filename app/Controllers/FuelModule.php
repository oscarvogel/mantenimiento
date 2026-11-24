<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Telematic\GetConciliacionCombustible;
use App\Application\Trips\FuelPanelFilters;
use App\Application\Trips\FuelPriceEntry;
use App\Application\Trips\GetPanelCombustible;
use App\Application\Trips\RegisterTripFuelCommand;
use App\Controllers\Traits\InterpretsTripCostInput;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

/**
 * Módulo de Combustible: el combustible cargado por cada viaje.
 *
 * Es la pantalla de trabajo del día a día: qué se cargó, cuánto consume, y
 * cargar lo que falta. Se apoya en la **misma** tabla que usa la conciliación
 * (`viaje_operativo_combustible`), que es la fuente de verdad del sistema.
 *
 * - `GetPanelCombustible` (Viajes) lee las cargas del viaje.
 * - `GetConciliacionCombustible` (Telemetría) compara lo declarado con el
 *   sensor, y solo se consulta si el usuario tiene `gerencial.flota.ver`: es
 *   un dato gerencial y va en su propia pestaña.
 *
 * El historial importado del ERP no se mezcla: es otro conjunto de datos.
 */
final class FuelModule extends BaseController
{
    use InterpretsTripCostInput;

    private const BASE = 'viajes/combustible';

    public function index(): string|ResponseInterface|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $filters = FuelPanelFilters::fromQuery($this->request->getGet());

            $panel = service('panelCombustible')->execute($actor, $filters);
            $conciliacion = $this->conciliacion($actor, $filters);
            $puedeEditar = $actor->hasPermission('viajes.operar');

            return $this->renderApp($actor, 'fuel-module', 'fuel-module', 'Combustible', [
                'panel' => $this->conEnlaces($panel),
                'conciliacion' => $conciliacion,
                'filtros' => [
                    'desde' => $filters->from,
                    'hasta' => $filters->to,
                    'camion' => $filters->equipmentId,
                    'sucursal' => $filters->branchId,
                ],
                'opciones' => [
                    'camiones' => service('panelCombustible')->camiones($actor),
                    'monedas' => $puedeEditar ? service('tripCurrencies')->execute($actor) : [],
                ],
                'puedeEditar' => $puedeEditar,
                'puedeConciliar' => $actor->hasPermission(GetConciliacionCombustible::PERMISO),
                'urls' => [
                    'index' => base_url(self::BASE),
                    'portal' => base_url('inicio'),
                    'pending' => base_url('viajes/operativos/combustible-sin-precio'),
                    'trips' => base_url('viajes/operativos'),
                    'fleet' => base_url('gerencial/flota?vista=combustible'),
                    'precios' => base_url(self::BASE . '/precios'),
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    /**
     * Registra una carga de combustible en un viaje.
     *
     * Es la misma operación que se hace desde la ficha del viaje: el módulo
     * no tiene un modelo de combustible propio, sólo deja capturar más rápido
     * lo que falta.
     */
    public function cargar(int $tripId): RedirectResponse
    {
        try {
            $command = new RegisterTripFuelCommand(
                $tripId,
                $this->requiredDateTime('fecha'),
                $this->requiredFloat('litros'),
                $this->optionalAmount('importe'),
                $this->currency(),
                $this->rate(),
                $this->optionalInt('kilometraje'),
                $this->text('estacion', 120),
                $this->text('comprobante', 80),
                $this->text('observaciones', 500),
            );
            service('registerTripFuel')->execute($this->actor(), $command);

            return redirect()->to($this->volver())->with('success', 'La carga de combustible quedó registrada.');
        } catch (Throwable $exception) {
            return $this->failure($exception, $this->volver());
        }
    }

    /**
     * Completa el importe de las cargas que estaban esperando su precio.
     *
     * Acepta sólo las cargas del viaje que se está completando: una carga de
     * otro viaje se ignora en vez de completarse por error.
     */
    public function completarPrecios(): RedirectResponse
    {
        try {
            $entries = FuelPriceEntry::fromPost((array) $this->request->getPost());
            if ($entries === []) {
                throw new DomainException('Cargá el importe de al menos una carga de combustible.');
            }

            $saved = service('completeFuelPrices')->execute($this->actor(), $entries);

            return redirect()->to($this->volver())->with('success', $saved === 1
                ? 'Se guardó el precio de 1 carga de combustible.'
                : sprintf('Se guardaron los precios de %d cargas de combustible.', $saved));
        } catch (Throwable $exception) {
            return $this->failure($exception, $this->volver());
        }
    }

    /**
     * La conciliación con el sensor es un dato gerencial: si el usuario no tiene
     * ese permiso, el módulo muestra el combustible declarado y no inventa una
     * comparación.
     *
     * @return array<string,mixed>|null
     */
    private function conciliacion(ActorContext $actor, FuelPanelFilters $filters): ?array
    {
        if (! $actor->hasPermission(GetConciliacionCombustible::PERMISO)) {
            return null;
        }

        return service('conciliacionCombustible')->execute($actor, $this->dias($filters), $filters->branchId);
    }

    /**
     * La conciliación trabaja con un número de días: se toma el del período
     * pedido, con el tope que admite esa lectura.
     */
    private function dias(FuelPanelFilters $filters): int
    {
        if ($filters->from === null || $filters->to === null) {
            return 30;
        }

        $dias = (int) ceil((strtotime($filters->to) - strtotime($filters->from)) / 86400);

        return max(1, min(90, $dias));
    }

    /**
     * Enlaces de cada fila: la ficha del viaje y dónde cargar la carga.
     *
     * @param array<string,mixed> $panel
     *
     * @return array<string,mixed>
     */
    private function conEnlaces(array $panel): array
    {
        foreach ($panel['viajes'] as $indice => $viaje) {
            $panel['viajes'][$indice]['url_viaje'] = base_url('viajes/operativos/' . (int) $viaje['id']);
            $panel['viajes'][$indice]['url_cargar'] = base_url(self::BASE . '/' . (int) $viaje['id'] . '/carga');
        }

        return $panel;
    }

    /**
     * Vuelve al módulo con los filtros que el operador estaba usando.
     */
    private function volver(): string
    {
        $query = [];
        foreach (['desde' => 'desde', 'hasta' => 'hasta', 'camion' => 'camion'] as $campo => $clave) {
            $valor = trim((string) $this->request->getPost($campo));
            if ($valor !== '') {
                $query[$clave] = $valor;
            }
        }

        $base = base_url(self::BASE);

        return $query === [] ? $base : $base . '?' . http_build_query($query);
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new DomainException('No existe un contexto autenticado válido.');
        }

        return $actor;
    }

    private function failure(Throwable $exception, ?string $location = null): ResponseInterface|RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló el módulo de combustible: {message}', ['message' => $exception->getMessage()]);
        }

        $mensaje = $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo cargar el módulo de combustible.';
        if ($location !== null) {
            return redirect()->to($location)->withInput()->with('error', $mensaje);
        }

        if ($this->request->getGet() !== []) {
            return redirect()->to(base_url(self::BASE))->with('error', $mensaje);
        }

        return $this->response->setStatusCode(403)->setBody(esc($mensaje));
    }
}
