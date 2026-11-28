<?php

declare(strict_types=1);

namespace App\Filters;

use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class PermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // `permission:a,b` alcanza con uno de los dos. Sin argumentos no pasa nadie.
        $permissions = is_array($arguments) ? $arguments : [];
        $actor       = (new SessionActorContext())->current();

        foreach ($permissions as $permission) {
            if (is_string($permission) && $permission !== '' && $actor !== null && $actor->hasPermission($permission)) {
                return null;
            }
        }

        // 403 real con una pantalla en español. El mensaje NO revela que permiso
        // faltaba ni que ruta se pidio: solo explica que el acceso quedo fuera.
        // Va en texto plano: la vista lo escapa con esc().
        $message = $actor === null
            ? 'Tu sesion no esta activa. Volve a iniciar sesion para continuar.'
            : 'Tu usuario no tiene permiso para acceder a esta seccion.';

        return service('response')
            ->setStatusCode(403)
            ->setContentType('text/html', 'UTF-8')
            ->setBody(view('errors/forbidden', ['message' => $message]));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Sin transformación de respuesta.
    }
}
