<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Organization\AssignUserCompanyHandler;
use App\Application\Organization\AssignUserRolesHandler;
use App\Application\Organization\CreateCompanyHandler;
use App\Application\Organization\CreateCompanyAdministratorCommand;
use App\Application\Organization\CreateCompanyAdministratorHandler;
use App\Application\Organization\GetOrganizationOverview;
use App\Application\Organization\UpdateCompanyHandler;
use App\Infrastructure\Identity\SessionActorContext;
use App\Presentation\PageSize;
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
        $companiesPage = max(1, (int) $this->request->getGet('companies_page'));
        $usersPage = max(1, (int) $this->request->getGet('users_page'));
        $companiesPerPage = PageSize::normalize($this->request->getGet('companies_per_page'));
        $usersPerPage = PageSize::normalize($this->request->getGet('users_per_page'));

        return $this->renderApp(
            $actor,
            'superadmin',
            'superadmin',
            'Administración global',
            service('administrationPayload')->superadmin($overview->execute(
                $actor,
                $companiesPage,
                $companiesPerPage,
                $usersPage,
                $usersPerPage,
            )),
        );
    }

    public function createCompany(): RedirectResponse
    {
        if (! $this->validate([
            'razon_social'   => 'required|max_length[255]',
            'nombre_fantasia'=> 'permit_empty|max_length[255]',
            'cuit'           => 'permit_empty|max_length[20]',
            'email'          => 'permit_empty|valid_email|max_length[255]',
            'email_notificaciones' => 'permit_empty|valid_email|max_length[255]',
            'notificaciones_email_habilitadas' => 'required|in_list[0,1]',
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
                'email_notificaciones' => $this->nullablePost('email_notificaciones'),
                'notificaciones_email_habilitadas' => (int) $this->request->getPost('notificaciones_email_habilitadas'),
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
            'email_notificaciones' => 'permit_empty|valid_email|max_length[255]',
            'notificaciones_email_habilitadas' => 'required|in_list[0,1]',
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
                'email_notificaciones' => $this->nullablePost('email_notificaciones'),
                'notificaciones_email_habilitadas' => (int) $this->request->getPost('notificaciones_email_habilitadas'),
                'telefono'        => $this->nullablePost('telefono'),
                'estado'          => (int) $this->request->getPost('estado'),
            ]);

            return redirect()->to('/superadmin')->with('success', 'Empresa actualizada correctamente.');
        } catch (Throwable $exception) {
            return $this->operationFailure($exception);
        }
    }

    public function createCompanyAdministrator(): RedirectResponse
    {
        if (! $this->validate([
            'admin_empresa_id'             => 'required|is_natural_no_zero',
            'admin_nombre'                 => 'required|max_length[255]',
            'admin_email'                  => 'required|valid_email|max_length[255]',
            'admin_password'               => 'required|min_length[8]|max_length[255]',
            'admin_password_confirmation'  => 'required|matches[admin_password]',
            'admin_motivo'                 => 'required|min_length[5]|max_length[255]',
        ])) {
            return $this->validationFailure();
        }

        try {
            /** @var CreateCompanyAdministratorHandler $handler */
            $handler = service('createCompanyAdministrator');
            $handler->execute($this->actor(), new CreateCompanyAdministratorCommand(
                (int) $this->request->getPost('admin_empresa_id'),
                (string) $this->request->getPost('admin_nombre'),
                (string) $this->request->getPost('admin_email'),
                (string) $this->request->getPost('admin_password'),
                (string) $this->request->getPost('admin_motivo'),
            ));

            return redirect()->to('/superadmin')->with(
                'success',
                'Administrador creado y asignado a la empresa correctamente.',
            );
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
