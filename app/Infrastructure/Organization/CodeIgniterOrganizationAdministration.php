<?php

declare(strict_types=1);

namespace App\Infrastructure\Organization;

use App\Application\Organization\Port\OrganizationAdministrationPort;
use CodeIgniter\Database\BaseConnection;
use DomainException;
use JsonException;
use RuntimeException;
use Throwable;

final class CodeIgniterOrganizationAdministration implements OrganizationAdministrationPort
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function overview(int $companiesPage, int $companiesPerPage, int $usersPage, int $usersPerPage): array
    {
        $companiesPage = max(1, $companiesPage);
        $usersPage = max(1, $usersPage);
        $companiesPerPage = max(1, $companiesPerPage);
        $usersPerPage = max(1, $usersPerPage);
        $companiesTotal = $this->database->table('empresas')->where('deleted_at', null)->countAllResults();
        $companiesPage = min($companiesPage, max(1, (int) ceil($companiesTotal / $companiesPerPage)));
        $companiesActive = $this->database->table('empresas')->where('deleted_at', null)->where('estado', 1)->countAllResults();
        $companies = $this->database->table('empresas')
            ->select('id, razon_social, nombre_fantasia, cuit, email, telefono, estado')
            ->where('deleted_at', null)
            ->orderBy('razon_social')
            ->orderBy('id')
            ->limit($companiesPerPage, ($companiesPage - 1) * $companiesPerPage)
            ->get()->getResultArray();

        $assignableCompanies = $this->database->table('empresas')
            ->select('id, razon_social, nombre_fantasia')
            ->where('deleted_at', null)->where('estado', 1)
            ->orderBy('razon_social')->orderBy('id')->get()->getResultArray();

        $usersTotal = $this->database->table('usuarios')->where('deleted_at', null)->countAllResults();
        $usersPage = min($usersPage, max(1, (int) ceil($usersTotal / $usersPerPage)));
        $users = $this->database->table('usuarios u')
            ->select('u.id, u.empresa_id, u.nombre, u.email, u.es_superadmin, u.activo, e.razon_social AS empresa_nombre')
            ->join('empresas e', 'e.id = u.empresa_id', 'left')
            ->where('u.deleted_at', null)
            ->orderBy('u.es_superadmin', 'DESC')
            ->orderBy('u.nombre')
            ->orderBy('u.id')
            ->limit($usersPerPage, ($usersPage - 1) * $usersPerPage)
            ->get()->getResultArray();

        $roleRows = [];
        if ($users !== []) {
            $roleRows = $this->database->table('usuario_roles ur')
                ->select('ur.usuario_id, r.id, r.nombre')
                ->join('roles r', 'r.id = ur.rol_id', 'inner')
                ->whereIn('ur.usuario_id', array_column($users, 'id'))
                ->orderBy('r.nombre')
                ->get()->getResultArray();
        }
        $rolesByUser = [];

        foreach ($roleRows as $role) {
            $rolesByUser[(int) $role['usuario_id']][] = [
                'id'     => (int) $role['id'],
                'nombre' => $role['nombre'],
            ];
        }

        foreach ($users as &$user) {
            $user['roles'] = $rolesByUser[(int) $user['id']] ?? [];
        }
        unset($user);

        $roles = $this->database->table('roles')
            ->select('id, nombre, descripcion')
            ->orderBy('nombre')
            ->get()->getResultArray();

        return [
            'companies' => $companies,
            'assignableCompanies' => $assignableCompanies,
            'companiesTotal' => $companiesTotal,
            'companiesActive' => $companiesActive,
            'companiesPage' => $companiesPage,
            'companiesPerPage' => $companiesPerPage,
            'users'     => $users,
            'usersTotal' => $usersTotal,
            'usersPage' => $usersPage,
            'usersPerPage' => $usersPerPage,
            'roles'     => $roles,
        ];
    }

    public function createCompany(array $data, int $actorUserId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->database->table('empresas')->insert([
            ...$data,
            'estado'     => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
        ]);

        $companyId = (int) $this->database->insertID();
        if ($companyId <= 0) {
            throw new RuntimeException('No se pudo crear la empresa.');
        }

        log_message('notice', 'Superadministrador {actor} creó empresa {company}', [
            'actor'   => $actorUserId,
            'company' => $companyId,
        ]);

        return $companyId;
    }

    public function createCompanyAdministrator(
        int $companyId,
        array $data,
        string $reason,
        int $actorUserId,
    ): int {
        $this->database->transBegin();

        try {
            $company = $this->database->query(
                'SELECT id FROM empresas WHERE id = ? AND estado = 1 AND deleted_at IS NULL FOR UPDATE',
                [$companyId],
            )->getRowArray();
            if ($company === null) {
                throw new DomainException('La empresa no existe o estÃ¡ inactiva.');
            }

            $existingUser = $this->database->table('usuarios')
                ->select('id')
                ->where('email', $data['email'])
                ->get()->getRowArray();
            if ($existingUser !== null) {
                throw new DomainException('Ya existe un usuario con ese email.');
            }

            $administratorRole = $this->database->query(
                'SELECT id FROM roles WHERE nombre = ? FOR UPDATE',
                ['Administrador'],
            )->getRowArray();
            if ($administratorRole === null) {
                throw new DomainException('El rol Administrador no estÃ¡ configurado.');
            }

            $now = date('Y-m-d H:i:s');
            $this->database->table('usuarios')->insert([
                'empresa_id'    => $companyId,
                'nombre'        => $data['nombre'],
                'email'         => $data['email'],
                'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
                'es_superadmin' => 0,
                'activo'        => 1,
                'created_at'    => $now,
                'updated_at'    => $now,
                'created_by'    => $actorUserId,
                'updated_by'    => $actorUserId,
            ]);
            $userId = (int) $this->database->insertID();
            if ($userId <= 0) {
                throw new RuntimeException('No se pudo crear el administrador.');
            }

            $roleId = (int) $administratorRole['id'];
            $this->database->table('usuario_roles')->insert([
                'usuario_id' => $userId,
                'rol_id'     => $roleId,
                'created_at' => $now,
            ]);

            // El rol Administrador representa acceso a todas las sucursales activas
            // de la empresa, por lo que no necesita filas en usuario_sucursales.
            $this->appendHistory(
                $userId,
                $companyId,
                'USUARIO_CREADO',
                [],
                [
                    'nombre'      => $data['nombre'],
                    'email'       => $data['email'],
                    'roles'       => [$roleId],
                    'sucursales'  => [],
                    'alcance'     => 'TODAS_LAS_SUCURSALES',
                ],
                $reason,
                $actorUserId,
            );

            $this->commitOrFail();

            return $userId;
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }

    public function updateCompany(int $companyId, array $data, int $actorUserId): void
    {
        $exists = $this->database->table('empresas')
            ->select('id')
            ->where('id', $companyId)
            ->where('deleted_at', null)
            ->get()->getRowArray();

        if ($exists === null) {
            throw new DomainException('La empresa no existe.');
        }

        if ($data['estado'] === 0) {
            $activeUsers = $this->database->table('usuarios')
                ->where('empresa_id', $companyId)
                ->where('activo', 1)
                ->where('es_superadmin', 0)
                ->where('deleted_at', null)
                ->countAllResults();
            if ($activeUsers > 0) {
                throw new DomainException('No se puede inactivar una empresa que todavía tiene usuarios activos.');
            }
        }

        $this->database->table('empresas')->where('id', $companyId)->update([
            ...$data,
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => $actorUserId,
        ]);

        log_message('notice', 'Superadministrador {actor} actualizó empresa {company}', [
            'actor'   => $actorUserId,
            'company' => $companyId,
        ]);
    }

    public function assignUserToCompany(int $userId, int $companyId, string $reason, int $actorUserId): void
    {
        $this->database->transBegin();

        try {
            $user = $this->lockedUser($userId);
            if ((bool) $user['es_superadmin']) {
                throw new DomainException('No se puede asignar una empresa al Superadministrador.');
            }

            $company = $this->database->query(
                'SELECT id FROM empresas WHERE id = ? AND estado = 1 AND deleted_at IS NULL FOR UPDATE',
                [$companyId],
            )->getRowArray();
            if ($company === null) {
                throw new DomainException('La empresa de destino no existe o está inactiva.');
            }

            $previousCompanyId = $user['empresa_id'] === null ? null : (int) $user['empresa_id'];
            if ($previousCompanyId === $companyId) {
                $this->database->transCommit();

                return;
            }

            $previousRoles = $this->roleIds($userId);
            $previousBranches = $this->branchIds($userId);

            $this->database->table('usuarios')->where('id', $userId)->update([
                'empresa_id' => $companyId,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $actorUserId,
            ]);
            $this->database->table('usuario_roles')->where('usuario_id', $userId)->delete();
            $this->database->table('usuario_sucursales')->where('usuario_id', $userId)->delete();

            $this->appendHistory(
                $userId,
                $companyId,
                'CAMBIO_EMPRESA',
                [
                    'empresa_id'   => $previousCompanyId,
                    'roles'        => $previousRoles,
                    'sucursales'   => $previousBranches,
                ],
                [
                    'empresa_id'   => $companyId,
                    'roles'        => [],
                    'sucursales'   => [],
                ],
                $reason,
                $actorUserId,
            );

            $this->commitOrFail();
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }

    public function assignRolesToUser(int $userId, array $roleIds, string $reason, int $actorUserId): void
    {
        $this->database->transBegin();

        try {
            $user = $this->lockedUser($userId);
            if ((bool) $user['es_superadmin']) {
                throw new DomainException('El Superadministrador no utiliza roles empresariales.');
            }
            if ($user['empresa_id'] === null) {
                throw new DomainException('El usuario debe pertenecer a una empresa antes de asignar roles.');
            }

            $validRows = $this->database->table('roles')
                ->select('id')
                ->whereIn('id', $roleIds)
                ->get()->getResultArray();
            $validRoleIds = array_map(static fn (array $row): int => (int) $row['id'], $validRows);
            sort($validRoleIds);
            sort($roleIds);

            if ($validRoleIds !== $roleIds) {
                throw new DomainException('Uno o más roles seleccionados no existen.');
            }

            $previousRoles = $this->roleIds($userId);
            $this->database->table('usuario_roles')->where('usuario_id', $userId)->delete();
            $now = date('Y-m-d H:i:s');

            foreach ($roleIds as $roleId) {
                $this->database->table('usuario_roles')->insert([
                    'usuario_id' => $userId,
                    'rol_id'     => $roleId,
                    'created_at' => $now,
                ]);
            }

            // Administrador obtiene todas las sucursales automáticamente.
            $administratorRole = $this->database->table('roles')
                ->select('id')
                ->where('nombre', 'Administrador')
                ->get()->getRowArray();
            if ($administratorRole !== null && in_array((int) $administratorRole['id'], $roleIds, true)) {
                $this->database->table('usuario_sucursales')->where('usuario_id', $userId)->delete();
            }

            $this->appendHistory(
                $userId,
                (int) $user['empresa_id'],
                'ASIGNACION_ROLES',
                ['roles' => $previousRoles],
                ['roles' => $roleIds],
                $reason,
                $actorUserId,
            );

            $this->commitOrFail();
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function lockedUser(int $userId): array
    {
        $user = $this->database->query(
            'SELECT id, empresa_id, es_superadmin FROM usuarios WHERE id = ? AND activo = 1 AND deleted_at IS NULL FOR UPDATE',
            [$userId],
        )->getRowArray();

        if ($user === null) {
            throw new DomainException('El usuario no existe o está inactivo.');
        }

        return $user;
    }

    /**
     * @return list<int>
     */
    private function roleIds(int $userId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['rol_id'],
            $this->database->table('usuario_roles')->select('rol_id')->where('usuario_id', $userId)->get()->getResultArray(),
        );
    }

    /**
     * @return list<int>
     */
    private function branchIds(int $userId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['sucursal_id'],
            $this->database->table('usuario_sucursales')->select('sucursal_id')->where('usuario_id', $userId)->get()->getResultArray(),
        );
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @throws JsonException
     */
    private function appendHistory(
        int $userId,
        ?int $companyId,
        string $action,
        array $before,
        array $after,
        string $reason,
        int $actorUserId,
    ): void {
        $this->database->table('usuario_acceso_historial')->insert([
            'usuario_id'               => $userId,
            'empresa_id'               => $companyId,
            'accion'                   => $action,
            'detalle_anterior'         => json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'detalle_nuevo'            => json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'motivo'                   => $reason,
            'actor_usuario_id'         => $actorUserId,
            'created_at'               => date('Y-m-d H:i:s'),
        ]);
    }

    private function commitOrFail(): void
    {
        if (! $this->database->transStatus()) {
            throw new RuntimeException('La transacción de acceso no pudo completarse.');
        }

        $this->database->transCommit();
    }
}
