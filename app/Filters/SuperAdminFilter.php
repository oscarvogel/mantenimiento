<?php

declare(strict_types=1);

namespace App\Filters;

use App\Infrastructure\Identity\CodeIgniterActorContextProvider;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class SuperAdminFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();
        $userId  = $session->get('usuario_id');

        if (! $userId) {
            return redirect()->to('/login')->with('error', 'Tenés que iniciar sesión.');
        }

        $actor = (new CodeIgniterActorContextProvider())->load((int) $userId);

        if ($actor === null) {
            $session->destroy();

            return redirect()->to('/login')->with('error', 'La cuenta ya no está activa o no tiene un alcance válido.');
        }

        (new SessionActorContext())->store($actor);

        if (! $actor->isSuperAdmin()) {
            return service('response')
                ->setStatusCode(403)
                ->setBody('Esta acción requiere acceso de Superadministrador.');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Sin transformación de respuesta.
    }
}
