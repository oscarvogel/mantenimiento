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
        $permissionExpression = is_array($arguments) ? ($arguments[0] ?? null) : null;
        $actor = (new SessionActorContext())->current();

        if (! is_string($permissionExpression) || trim($permissionExpression) === '' || $actor === null) {
            return $this->forbidden();
        }

        $permissions = array_values(array_filter(
            array_map('trim', explode('|', $permissionExpression)),
            static fn (string $permission): bool => $permission !== '',
        ));

        $allowed = $permissions !== [];
        if ($allowed) {
            $allowed = false;
            foreach ($permissions as $permission) {
                if ($actor->hasPermission($permission)) {
                    $allowed = true;
                    break;
                }
            }
        }

        if (! $allowed) {
            return $this->forbidden();
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Sin transformación de respuesta.
    }

    private function forbidden(): ResponseInterface
    {
        return service('response')
            ->setStatusCode(403)
            ->setBody('No tenés permiso para realizar esta acción.');
    }
}
