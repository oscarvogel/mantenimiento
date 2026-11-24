<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Telematic\GetConciliacionCombustible;
use App\Application\Trips\GetPanelCombustible;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

/**
 * Módulo de Combustible: lo que declaró cada viaje, lo que falta completar y
 * la conciliación contra el sensor del tanque.
 *
 * Es una pantalla de solo lectura que compone dos lecturas que ya existen y
 * que cada una valida su propio permiso y su alcance:
 *
 * - `GetPanelCombustible` (Viajes) lee la tabla de cargas del viaje: la
 *   fuente de verdad del módulo.
 * - `GetConciliacionCombustible` (Telemetría) compara lo declarado con el
 *   sensor, y solo se consulta si el usuario tiene `gerencial.flota.ver`.
 *
 * El historial importado del ERP no se mezcla: es otro conjunto de datos.
 */
final class FuelModule extends BaseController
{
    private const BASE = 'viajes/combustible';

    public function index(): string|ResponseInterface|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $dias = $this->entero('dias') ?? 30;
            $sucursalId = $this->entero('sucursal_id');

            $panel = service('panelCombustible')->execute($actor, $dias, $sucursalId);
            $conciliacion = $this->conciliacion($actor, $dias, $sucursalId);

            return $this->renderApp($actor, 'fuel-module', 'fuel-module', 'Combustible', [
                'panel' => $panel,
                'conciliacion' => $conciliacion,
                'urls' => [
                    'index' => base_url(self::BASE),
                    'portal' => base_url('inicio'),
                    'pending' => base_url('viajes/operativos/combustible-sin-precio'),
                    'trips' => base_url('viajes/operativos'),
                    'fleet' => base_url('gerencial/flota?vista=combustible'),
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    /**
     * La conciliación con el sensor es un dato gerencial: si el usuario no tiene
     * ese permiso, el módulo muestra el combustible declarado y no inventa una
     * comparación.
     *
     * @return array<string,mixed>|null
     */
    private function conciliacion(ActorContext $actor, int $dias, ?int $sucursalId): ?array
    {
        if (! $actor->hasPermission(GetConciliacionCombustible::PERMISO)) {
            return null;
        }

        return service('conciliacionCombustible')->execute($actor, $dias, $sucursalId);
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new DomainException('No existe un contexto autenticado válido.');
        }

        return $actor;
    }

    private function texto(string $clave): ?string
    {
        $valor = $this->request->getGet($clave);

        return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }

    /**
     * Un valor que no es un entero se rechaza en el caso de uso: `dias=abc` no
     * debe mostrar 30 días como si nada.
     */
    private function entero(string $clave): ?int
    {
        $valor = $this->texto($clave);
        if ($valor === null) {
            return null;
        }

        return ctype_digit($valor) ? (int) $valor : -1;
    }

    private function failure(Throwable $exception): ResponseInterface|RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló el módulo de combustible: {message}', ['message' => $exception->getMessage()]);
        }

        $mensaje = $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo cargar el módulo de combustible.';
        if ($this->request->getGet() !== []) {
            return redirect()->to(base_url(self::BASE))->with('error', $mensaje);
        }

        return $this->response->setStatusCode(403)->setBody(esc($mensaje));
    }
}
