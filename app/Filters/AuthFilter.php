<?php

namespace App\Filters;

use App\Infrastructure\Identity\CodeIgniterActorContextProvider;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();
        if (! $session->get('usuario_id')) {
            // Guardar la URL pedida para volver despues del login
            $session->set('redirect_after_login', current_url());
            return redirect()->to('/login')->with('error', 'Tenes que iniciar sesion.');
        }

        $actor = (new CodeIgniterActorContextProvider())->load((int) $session->get('usuario_id'));
        if ($actor === null) {
            $session->destroy();

            return redirect()->to('/login')->with('error', 'La cuenta ya no está activa o no tiene un alcance válido.');
        }

        (new SessionActorContext())->store($actor);
        $session->set('empresa_id', $actor->companyId());
        $session->set('es_superadmin', $actor->isSuperAdmin());
        $session->set('roles', $actor->roles());
        $session->set('permisos', $actor->permissions());
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // nada
    }
}
