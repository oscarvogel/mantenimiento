<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Organization\AssignUserCompanyHandler;
use App\Application\Organization\AssignUserRolesHandler;
use App\Application\Organization\CreateCompanyHandler;
use App\Application\Organization\GetOrganizationOverview;
use App\Application\Organization\UpdateCompanyHandler;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

final class SuperAdmin extends BaseController
{
    public function index(): string
    {
        /** @var GetOrganizationOverview $overview */
        $overview = service('organizationOverview');
        $actor = $this->actor();

        return $this->renderApp(
            $actor,
            'superadmin',
            'superadmin',
            'Administración global',
            service('administrationPayload')->superadmin($overview->execute($actor)),
        );
    }

    public function createCompany(): RedirectResponse
    {
        if (! $this->validate([
            'razon_social'   => 'required|max_length[255]',
            'nombre_fantasia'=> 'permit_empty|max_length[255]',
            'cuit'           => 'permit_empty|max_length[20]',
            'email'          => 'permit_empty|valid_email|max_length[255]',
            'telefono'       => 'permit_empty|max_length[50]',
        ])) {
            return $this->validationFailure();
        }

        try {
            /** @var CreateCompanyHandler $handler */
            $handler = service('createCompany');
            $handler->execute($this->actor(), [
                'razon_social'    => trim((string) $this->request->getPost('razon_social')),
                'nombre_fantasia' => $this->nullablePost('nombre_fantasia'),
                'cuit'            => $this->nullablePost('cuit'),
                'email'           => $this->nullablePost('email'),
                'telefono'        => $this->nullablePost('telefono'),
            ]);

            return redirect()->to('/superadmin')->with('success', 'Empresa creada correctamente.');
        } catch (Throwable $exception) {
            return $this->operationFailure($exception);
        }
    }

    public function updateCompany(int $companyId): RedirectResponse
    {
        if (! $this->validate([
            'razon_social'   => 'required|max_length[255]',
            'nombre_fantasia'=> 'permit_empty|max_length[255]',
            'cuit'           => 'permit_empty|max_length[20]',
            'email'          => 'permit_empty|valid_email|max_length[255]',
            'telefono'       => 'permit_empty|max_length[50]',
            'estado'         => 'required|in_list[0,1]',
        ])) {
            return $this->validationFailure();
        }

        try {
            /** @var UpdateCompanyHandler $handler */
            $handler = service('updateCompany');
            $handler->execute($this->actor(), $companyId, [
                'razon_social'    => trim((string) $this->request->getPost('razon_social')),
                'nombre_fantasia' => $this->nullablePost('nombre_fantasia'),
                'cuit'            => $this->nullablePost('cuit'),
                'email'           => $this->nullablePost('email'),
                'telefono'        => $this->nullablePost('telefono'),
                'estado'          => (int) $this->request->getPost('estado'),
            ]);

            return redirect()->to('/superadmin')->with('success', 'Empresa actualizada correctamente.');
        } catch (Throwable $exception) {
            return $this->operationFailure($exception);
        }
    }

    public function assignCompany(int $userId): RedirectResponse
    {
        if (! $this->validate([
            'empresa_id' => 'required|is_natural_no_zero',
            'motivo'     => 'required|min_length[5]|max_length[255]',
        ])) {
            return $this->validationFailure();
        }

        try {
            /** @var AssignUserCompanyHandler $handler */
            $handler = service('assignUserCompany');
            $handler->execute(
                $this->actor(),
                $userId,
                (int) $this->request->getPost('empresa_id'),
                (string) $this->request->getPost('motivo'),
            );

            return redirect()->to('/superadmin')->with(
                'success',
                'Empresa asignada. Si cambió, los roles y sucursales anteriores fueron retirados.',
            );
        } catch (Throwable $exception) {
            return $this->operationFailure($exception);
        }
    }

    public function assignRoles(int $userId): RedirectResponse
    {
        $postedRoles = $this->request->getPost('roles');
        $roleIds     = is_array($postedRoles)
            ? array_values(array_map('intval', $postedRoles))
            : [];

        if (! $this->validate([
            'motivo' => 'required|min_length[5]|max_length[255]',
        ])) {
            return $this->validationFailure();
        }

        try {
            /** @var AssignUserRolesHandler $handler */
            $handler = service('assignUserRoles');
            $handler->execute(
                $this->actor(),
                $userId,
                $roleIds,
                (string) $this->request->getPost('motivo'),
            );

            return redirect()->to('/superadmin')->with('success', 'Roles actualizados correctamente.');
        } catch (Throwable $exception) {
            return $this->operationFailure($exception);
        }
    }

    private function actor(): \App\Application\Identity\ActorContext
    {
        $actor = (new SessionActorContext())->current();

        if ($actor === null) {
            throw new DomainException('No existe un contexto autenticado válido.');
        }

        return $actor;
    }

    private function nullablePost(string $field): ?string
    {
        $value = trim((string) $this->request->getPost($field));

        return $value === '' ? null : $value;
    }

    private function validationFailure(): RedirectResponse
    {
        return redirect()->to('/superadmin')->withInput()->with(
            'error',
            implode(' ', $this->validator->getErrors()),
        );
    }

    private function operationFailure(Throwable $exception): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló administración global: {message}', ['message' => $exception->getMessage()]);
        }

        $message = $exception instanceof DomainException
            ? $exception->getMessage()
            : 'No se pudo completar la operación.';

        return redirect()->to('/superadmin')->withInput()->with('error', $message);
    }
}
