<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Organization\TenantAdministrationService;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

final class TenantAdmin extends BaseController
{
    public function branches(): string
    {
        $actor = $this->actor();

        return $this->renderApp(
            $actor,
            'branches',
            'branches-admin',
            'Administración de sucursales',
            service('administrationPayload')->branches($this->service()->branchesOverview($actor), $actor),
        );
    }

    public function createBranch(): RedirectResponse
    {
        if (! $this->validate([
            'codigo'        => 'required|max_length[20]',
            'nombre'        => 'required|max_length[255]',
            'direccion'     => 'permit_empty|max_length[255]',
            'email_alertas' => 'permit_empty|valid_email|max_length[255]',
        ])) {
            return $this->validationFailure('/administracion/sucursales');
        }

        try {
            $this->service()->createBranch($this->actor(), $this->branchData(false));

            return redirect()->to('/administracion/sucursales')->with('success', 'Sucursal creada correctamente.');
        } catch (Throwable $exception) {
            return $this->operationFailure('/administracion/sucursales', $exception);
        }
    }

    public function updateBranch(int $branchId): RedirectResponse
    {
        if (! $this->validate([
            'codigo'        => 'required|max_length[20]',
            'nombre'        => 'required|max_length[255]',
            'direccion'     => 'permit_empty|max_length[255]',
            'email_alertas' => 'permit_empty|valid_email|max_length[255]',
            'estado'        => 'required|in_list[0,1]',
        ])) {
            return $this->validationFailure('/administracion/sucursales');
        }

        try {
            $this->service()->updateBranch($this->actor(), $branchId, $this->branchData(true));

            return redirect()->to('/administracion/sucursales')->with('success', 'Sucursal actualizada correctamente.');
        } catch (Throwable $exception) {
            return $this->operationFailure('/administracion/sucursales', $exception);
        }
    }

    public function users(): string
    {
        $actor = $this->actor();

        return $this->renderApp(
            $actor,
            'users',
            'users-admin',
            'Administración de usuarios',
            service('administrationPayload')->users($this->service()->usersOverview($actor), $actor),
        );
    }

    public function createUser(): RedirectResponse
    {
        if (! $this->validate([
            'nombre'               => 'required|max_length[255]',
            'email'                => 'required|valid_email|max_length[255]',
            'password'             => 'required|min_length[8]|max_length[255]',
            'password_confirmation'=> 'required|matches[password]',
            'motivo'               => 'required|min_length[5]|max_length[255]',
        ])) {
            return $this->validationFailure('/administracion/usuarios');
        }

        try {
            $this->service()->createUser(
                $this->actor(),
                [
                    'nombre'   => (string) $this->request->getPost('nombre'),
                    'email'    => (string) $this->request->getPost('email'),
                    'password' => (string) $this->request->getPost('password'),
                ],
                $this->postedIds('roles'),
                $this->postedIds('sucursales'),
                (string) $this->request->getPost('motivo'),
            );

            return redirect()->to('/administracion/usuarios')->with('success', 'Usuario creado correctamente.');
        } catch (Throwable $exception) {
            return $this->operationFailure('/administracion/usuarios', $exception);
        }
    }

    public function updateUser(int $userId): RedirectResponse
    {
        if (! $this->validate([
            'nombre' => 'required|max_length[255]',
            'email'  => 'required|valid_email|max_length[255]',
            'activo' => 'required|in_list[0,1]',
            'motivo' => 'required|min_length[5]|max_length[255]',
        ])) {
            return $this->validationFailure('/administracion/usuarios');
        }

        try {
            $data = [
                'nombre' => (string) $this->request->getPost('nombre'),
                'email'  => (string) $this->request->getPost('email'),
                'activo' => (int) $this->request->getPost('activo'),
            ];
            $this->service()->updateUser(
                $this->actor(),
                $userId,
                $data,
                (string) $this->request->getPost('motivo'),
            );
            if ($userId === $this->actor()->userId()) {
                session()->set('usuario_nombre', trim($data['nombre']));
                session()->set('usuario_email', mb_strtolower(trim($data['email'])));
            }

            return redirect()->to('/administracion/usuarios')->with('success', 'Usuario actualizado correctamente.');
        } catch (Throwable $exception) {
            return $this->operationFailure('/administracion/usuarios', $exception);
        }
    }

    public function assignUserAccess(int $userId): RedirectResponse
    {
        if (! $this->validate(['motivo' => 'required|min_length[5]|max_length[255]'])) {
            return $this->validationFailure('/administracion/usuarios');
        }

        try {
            $this->service()->assignUserAccess(
                $this->actor(),
                $userId,
                $this->postedIds('roles'),
                $this->postedIds('sucursales'),
                (string) $this->request->getPost('motivo'),
            );

            return redirect()->to('/administracion/usuarios')->with('success', 'Roles y sucursales actualizados.');
        } catch (Throwable $exception) {
            return $this->operationFailure('/administracion/usuarios', $exception);
        }
    }

    public function resetUserPassword(int $userId): RedirectResponse
    {
        if (! $this->validate([
            'password'              => 'required|min_length[8]|max_length[255]',
            'password_confirmation' => 'required|matches[password]',
            'motivo'                => 'required|min_length[5]|max_length[255]',
        ])) {
            return $this->validationFailure('/administracion/usuarios');
        }

        try {
            $this->service()->resetUserPassword(
                $this->actor(),
                $userId,
                (string) $this->request->getPost('password'),
                (string) $this->request->getPost('motivo'),
            );

            return redirect()->to('/administracion/usuarios')->with('success', 'Contraseña restablecida correctamente.');
        } catch (Throwable $exception) {
            return $this->operationFailure('/administracion/usuarios', $exception);
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

    private function service(): TenantAdministrationService
    {
        /** @var TenantAdministrationService $service */
        $service = service('tenantAdministration');

        return $service;
    }

    private function branchData(bool $withState): array
    {
        $data = [
            'codigo'        => (string) $this->request->getPost('codigo'),
            'nombre'        => (string) $this->request->getPost('nombre'),
            'direccion'     => $this->request->getPost('direccion'),
            'email_alertas' => $this->request->getPost('email_alertas'),
        ];
        if ($withState) {
            $data['estado'] = (int) $this->request->getPost('estado');
        }

        return $data;
    }

    /** @return list<int> */
    private function postedIds(string $field): array
    {
        $values = $this->request->getPost($field);

        return is_array($values) ? array_values(array_map('intval', $values)) : [];
    }

    private function validationFailure(string $url): RedirectResponse
    {
        return redirect()->to($url)->withInput()->with('error', implode(' ', $this->validator->getErrors()));
    }

    private function operationFailure(string $url, Throwable $exception): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló administración de empresa: {message}', ['message' => $exception->getMessage()]);
        }

        return redirect()->to($url)->withInput()->with(
            'error',
            $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo completar la operación.',
        );
    }
}
