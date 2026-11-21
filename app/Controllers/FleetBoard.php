<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Gerencial\GetTableroFlota;
use App\Application\Identity\ActorContext;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

/**
 * Tablero de flota de Gerencial. Solo lectura: traduce los filtros del GET y
 * delega en el coordinador `GetTableroFlota`.
 */
final class FleetBoard extends BaseController
{
    private const BASE = 'gerencial/flota';

    public function index(): string|ResponseInterface|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $tablero = $this->tablero()->execute(
                $actor,
                $this->texto('vista'),
                $this->entero('dias'),
                $this->entero('sucursal_id'),
            );

            return $this->renderApp($actor, 'fleet-board', 'fleet-board', 'Tablero de flota', [
                'tablero' => $tablero,
                'urls' => [
                    'index' => base_url(self::BASE),
                    'portal' => base_url('inicio'),
                    'equipment' => base_url('mantenimiento/equipos'),
                ],
            ]);
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

    private function tablero(): GetTableroFlota
    {
        return service('tableroFlota');
    }

    private function texto(string $clave): ?string
    {
        $valor = $this->request->getGet($clave);

        return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }

    /**
     * Un valor que no es un entero positivo se rechaza en el caso de uso, no se
     * descarta en silencio: `dias=abc` no debe mostrar 30 días como si nada.
     */
    private function entero(string $clave): ?int
    {
        $valor = $this->texto($clave);
        if ($valor === null) {
            return null;
        }

        return ctype_digit($valor) ? (int) $valor : -1;
    }

    /**
     * Con filtros, un error vuelve al tablero sin filtros con el mensaje. Sin
     * filtros no hay a dónde volver: se responde 403 para no entrar en un bucle
     * de redirecciones.
     */
    private function failure(Throwable $exception): ResponseInterface|RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló el tablero de flota: {message}', ['message' => $exception->getMessage()]);
        }

        $mensaje = $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo armar el tablero de flota.';
        if ($this->request->getGet() !== []) {
            return redirect()->to(base_url(self::BASE))->with('error', $mensaje);
        }

        return $this->response->setStatusCode(403)->setBody(esc($mensaje));
    }
}
