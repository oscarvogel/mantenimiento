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
use App\Infrastructure\Notifications\CodeIgniterCompanyNotificationRecipientResolver;
use App\Infrastructure\Notifications\CodeIgniterEmailNotificationGateway;
use App\Infrastructure\Notifications\SystemNotificationClock;
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
        $data = $overview->execute($actor, $companiesPage, $companiesPerPage, $usersPage, $usersPerPage);

        if (isset($data['companies']) && is_array($data['companies']) && $data['companies'] !== []) {
            $ids = array_map('intval', array_column($data['companies'], 'id'));
            $rows = db_connect()->table('empresas')->select('id, ia_habilitada')->whereIn('id', $ids)->get()->getResultArray();
            $aiByCompany = [];
            foreach ($rows as $row) {
                $aiByCompany[(int) $row['id']] = (int) ($row['ia_habilitada'] ?? 0);
            }
            foreach ($data['companies'] as &$company) {
                $company['ia_habilitada'] = $aiByCompany[(int) $company['id']] ?? 0;
            }
            unset($company);
        }

        $payload = service('administrationPayload')->superadmin($data);
        $payload['aiCompanyControls'] = array_map(static fn (array $company): array => [
            'id' => (int) $company['id'],
            'displayName' => $company['nombre_fantasia'] ?: $company['razon_social'],
            'enabled' => (int) ($company['ia_habilitada'] ?? 0) === 1,
            'action' => base_url('superadmin/empresas/' . $company['id']),
            'fields' => [
                'razon_social' => (string) $company['razon_social'],
                'nombre_fantasia' => (string) ($company['nombre_fantasia'] ?? ''),
                'cuit' => (string) ($company['cuit'] ?? ''),
                'email' => (string) ($company['email'] ?? ''),
                'email_notificaciones' => (string) ($company['email_notificaciones'] ?? ''),
                'notificaciones_email_habilitadas' => (string) ((int) ($company['notificaciones_email_habilitadas'] ?? 1)),
                'telefono' => (string) ($company['telefono'] ?? ''),
                'estado' => (string) ((int) $company['estado']),
            ],
        ], $data['companies'] ?? []);

        return $this->renderApp($actor, 'superadmin', 'superadmin', 'Administración global', $payload);
    }

    public function createCompany(): RedirectResponse
    {
        if (! $this->validate([
            'razon_social' => 'required|max_length[255]',
            'nombre_fantasia' => 'permit_empty|max_length[255]',
            'cuit' => 'permit_empty|max_length[20]',
            'email' => 'permit_empty|valid_email|max_length[255]',
            'email_notificaciones' => 'permit_empty|valid_email|max_length[255]',
            'notificaciones_email_habilitadas' => 'required|in_list[0,1]',
            'telefono' => 'permit_empty|max_length[50]',
        ])) {
            return $this->validationFailure();
        }

        try {
            /** @var CreateCompanyHandler $handler */
            $handler = service('createCompany');
            $handler->execute($this->actor(), [
                'razon_social' => trim((string) $this->request->getPost('razon_social')),
                'nombre_fantasia' => $this->nullablePost('nombre_fantasia'),
                'cuit' => $this->nullablePost('cuit'),
                'email' => $this->nullablePost('email'),
                'email_notificaciones' => $this->nullablePost('email_notificaciones'),
                'notificaciones_email_habilitadas' => (int) $this->request->getPost('notificaciones_email_habilitadas'),
                'ia_habilitada' => 0,
                'telefono' => $this->nullablePost('telefono'),
            ]);
            return redirect()->to('/superadmin')->with('success', 'Empresa creada correctamente.');
        } catch (Throwable $exception) {
            return $this->operationFailure($exception);
        }
    }

    public function updateCompany(int $companyId): RedirectResponse
    {
        if (! $this->validate([
            'razon_social' => 'required|max_length[255]',
            'nombre_fantasia' => 'permit_empty|max_length[255]',
            'cuit' => 'permit_empty|max_length[20]',
            'email' => 'permit_empty|valid_email|max_length[255]',
            'email_notificaciones' => 'permit_empty|valid_email|max_length[255]',
            'notificaciones_email_habilitadas' => 'required|in_list[0,1]',
            'ia_habilitada' => 'permit_empty|in_list[0,1]',
            'telefono' => 'permit_empty|max_length[50]',
            'estado' => 'required|in_list[0,1]',
        ])) {
            return $this->validationFailure();
        }

        try {
            $postedAi = $this->request->getPost('ia_habilitada');
            if ($postedAi === null || $postedAi === '') {
                $row = db_connect()->table('empresas')->select('ia_habilitada')->where('id', $companyId)->get()->getRowArray();
                if ($row === null) {
                    throw new DomainException('La empresa no existe.');
                }
                $postedAi = (string) ((int) ($row['ia_habilitada'] ?? 0));
            }

            /** @var UpdateCompanyHandler $handler */
            $handler = service('updateCompany');
            $handler->execute($this->actor(), $companyId, [
                'razon_social' => trim((string) $this->request->getPost('razon_social')),
                'nombre_fantasia' => $this->nullablePost('nombre_fantasia'),
                'cuit' => $this->nullablePost('cuit'),
                'email' => $this->nullablePost('email'),
                'email_notificaciones' => $this->nullablePost('email_notificaciones'),
                'notificaciones_email_habilitadas' => (int) $this->request->getPost('notificaciones_email_habilitadas'),
                'ia_habilitada' => (int) $postedAi,
                'telefono' => $this->nullablePost('telefono'),
                'estado' => (int) $this->request->getPost('estado'),
            ]);
            return redirect()->to('/superadmin')->with('success', 'Empresa actualizada correctamente.');
        } catch (Throwable $exception) {
            return $this->operationFailure($exception);
        }
    }

    public function testCompanyNotificationEmail(int $companyId): RedirectResponse
    {
        try {
            $recipient = (new CodeIgniterCompanyNotificationRecipientResolver(db_connect()))->resolve($companyId);
            if ($recipient === null) {
                throw new DomainException('La empresa no tiene un correo de notificaciones habilitado. Guardá un destinatario válido y habilitá el envío antes de probar.');
            }
            (new CodeIgniterEmailNotificationGateway(new SystemNotificationClock()))->sendDigest($recipient, [[
                'titulo' => 'Correo de prueba',
                'resumen' => 'La configuración SMTP y el destinatario de mantenimiento funcionan correctamente para esta empresa.',
                'url' => base_url('superadmin'),
            ]]);
            log_message('notice', 'Superadministrador {actor} envió correo de prueba para empresa {company} a {recipient}.', [
                'actor' => $this->actor()->userId(), 'company' => $companyId, 'recipient' => $recipient,
            ]);
            return redirect()->to('/superadmin')->with('success', 'Correo de prueba enviado a ' . $recipient . '.');
        } catch (Throwable $exception) {
            return $this->operationFailure($exception);
        }
    }

    public function createCompanyAdministrator(): RedirectResponse
    {
        if (! $this->validate([
            'admin_empresa_id' => 'required|is_natural_no_zero', 'admin_nombre' => 'required|max_length[255]',
            'admin_email' => 'required|valid_email|max_length[255]', 'admin_password' => 'required|min_length[8]|max_length[255]',
            'admin_password_confirmation' => 'required|matches[admin_password]', 'admin_motivo' => 'required|min_length[5]|max_length[255]',
        ])) {
            return $this->validationFailure();
        }
        try {
            /** @var CreateCompanyAdministratorHandler $handler */
            $handler = service('createCompanyAdministrator');
            $handler->execute($this->actor(), new CreateCompanyAdministratorCommand(
                (int) $this->request->getPost('admin_empresa_id'), (string) $this->request->getPost('admin_nombre'),
                (string) $this->request->getPost('admin_email'), (string) $this->request->getPost('admin_password'),
                (string) $this->request->getPost('admin_motivo'),
            ));
            return redirect()->to('/superadmin')->with('success', 'Administrador creado y asignado a la empresa correctamente.');
        } catch (Throwable $exception) {
            return $this->operationFailure($exception);
        }
    }

    public function assignCompany(int $userId): RedirectResponse
    {
        if (! $this->validate(['empresa_id' => 'required|is_natural_no_zero', 'motivo' => 'required|min_length[5]|max_length[255]'])) {
            return $this->validationFailure();
        }
        try {
            /** @var AssignUserCompanyHandler $handler */
            $handler = service('assignUserCompany');
            $handler->execute($this->actor(), $userId, (int) $this->request->getPost('empresa_id'), (string) $this->request->getPost('motivo'));
            return redirect()->to('/superadmin')->with('success', 'Empresa asignada. Si cambió, los roles y sucursales anteriores fueron retirados.');
        } catch (Throwable $exception) {
            return $this->operationFailure($exception);
        }
    }

    public function assignRoles(int $userId): RedirectResponse
    {
        $postedRoles = $this->request->getPost('roles');
        $roleIds = is_array($postedRoles) ? array_values(array_map('intval', $postedRoles)) : [];
        if (! $this->validate(['motivo' => 'required|min_length[5]|max_length[255]'])) {
            return $this->validationFailure();
        }
        try {
            /** @var AssignUserRolesHandler $handler */
            $handler = service('assignUserRoles');
            $handler->execute($this->actor(), $userId, $roleIds, (string) $this->request->getPost('motivo'));
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
        return redirect()->to('/superadmin')->withInput()->with('error', implode(' ', $this->validator->getErrors()));
    }

    private function operationFailure(Throwable $exception): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló administración global: {message}', ['message' => $exception->getMessage()]);
        }
        $message = $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo completar la operación.';
        return redirect()->to('/superadmin')->withInput()->with('error', $message);
    }
}
