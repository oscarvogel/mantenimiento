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
        $permission = is_array($arguments) ? ($arguments[0] ?? null) : null;
        $actor      = (new SessionActorContext())->current();

        if (! is_string($permission) || $permission === '' || $actor === null || ! $actor->hasPermission($permission)) {
            return service('response')
                ->setStatusCode(403)
                ->setBody('No tenés permiso para realizar esta acción.');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Sin transformación de respuesta.
    }
}
