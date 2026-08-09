<?php

declare(strict_types=1);

namespace App\Infrastructure\Organization;

use App\Application\Organization\Port\TenantAdministrationPort;
use CodeIgniter\Database\BaseConnection;
use DomainException;
use JsonException;
use RuntimeException;
use Throwable;

final class CodeIgniterTenantAdministration implements TenantAdministrationPort
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function branchesOverview(int $companyId): array
    {
        return [
            'company'  => $this->company($companyId),
            'branches' => $this->database->table('sucursales')
                ->select('id, empresa_id, codigo, nombre, direccion, email_alertas, estado')
                ->where('empresa_id', $companyId)
                ->where('deleted_at', null)
                ->orderBy('estado', 'DESC')
                ->orderBy('nombre')
                ->get()->getResultArray(),
        ];
    }

    public function usersOverview(int $companyId): array
    {
        $company = $this->company($companyId);
        $users = $this->database->table('usuarios')
            ->select('id, empresa_id, nombre, email, activo, ultimo_acceso')
            ->where('empresa_id', $companyId)
            ->where('es_superadmin', 0)
            ->where('deleted_at', null)
            ->orderBy('activo', 'DESC')
            ->orderBy('nombre')
            ->get()->getResultArray();

        $rolesByUser = [];
        $branchesByUser = [];
        if ($users !== []) {
            $userIds = array_map(static fn (array $user): int => (int) $user['id'], $users);
            $roleRows = $this->database->table('usuario_roles ur')
                ->select('ur.usuario_id, r.id, r.nombre')
                ->join('roles r', 'r.id = ur.rol_id', 'inner')
                ->whereIn('ur.usuario_id', $userIds)
                ->orderBy('r.nombre')
                ->get()->getResultArray();
            foreach ($roleRows as $role) {
                $rolesByUser[(int) $role['usuario_id']][] = [
                    'id' => (int) $role['id'],
                    'nombre' => $role['nombre'],
                ];
            }

            $branchRows = $this->database->table('usuario_sucursales us')
                ->select('us.usuario_id, s.id, s.codigo, s.nombre, s.estado')
                ->join('sucursales s', 's.id = us.sucursal_id', 'inner')
                ->whereIn('us.usuario_id', $userIds)
                ->where('s.empresa_id', $companyId)
                ->where('s.deleted_at', null)
                ->orderBy('s.nombre')
                ->get()->getResultArray();
            foreach ($branchRows as $branch) {
                $branchesByUser[(int) $branch['usuario_id']][] = [
                    'id' => (int) $branch['id'],
                    'codigo' => $branch['codigo'],
                    'nombre' => $branch['nombre'],
                    'estado' => (int) $branch['estado'],
                ];
            }
        }

        foreach ($users as &$user) {
            $userId = (int) $user['id'];
            $user['roles'] = $rolesByUser[$userId] ?? [];
            $user['branches'] = $branchesByUser[$userId] ?? [];
            $user['all_company_branches'] = in_array(
                'Administrador',
                array_column($user['roles'], 'nombre'),
                true,
            );
        }
        unset($user);

        return [
            'company'  => $company,
            'users'    => $users,
            'roles'    => $this->database->table('roles')->select('id, nombre, descripcion')->orderBy('nombre')->get()->getResultArray(),
            'branches' => $this->database->table('sucursales')
                ->select('id, codigo, nombre, estado')
                ->where('empresa_id', $companyId)
                ->where('deleted_at', null)
                ->orderBy('estado', 'DESC')
                ->orderBy('nombre')
                ->get()->getResultArray(),
        ];
    }

    public function createBranch(int $companyId, array $data, int $actorUserId): int
    {
        $this->company($companyId);
        $this->assertUniqueBranchCode($companyId, $data['codigo']);
        $now = date('Y-m-d H:i:s');
        $this->database->table('sucursales')->insert([
            ...$data,
            'empresa_id' => $companyId,
            'estado'     => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
        ]);

        $branchId = (int) $this->database->insertID();
        if ($branchId <= 0) {
            throw new RuntimeException('No se pudo crear la sucursal.');
        }

        log_message('notice', 'Usuario {actor} creó sucursal {branch} de empresa {company}', [
            'actor' => $actorUserId, 'branch' => $branchId, 'company' => $companyId,
        ]);

        return $branchId;
    }

    public function updateBranch(int $companyId, int $branchId, array $data, int $actorUserId): void
    {
        $this->database->transBegin();

        try {
            $branch = $this->lockedBranch($companyId, $branchId);
            $this->assertUniqueBranchCode($companyId, $data['codigo'], $branchId);
            if ((int) $branch['estado'] === 1 && $data['estado'] === 0 && $this->usersDependingOnBranch($companyId, $branchId) > 0) {
                throw new DomainException('No se puede inactivar: hay usuarios activos que quedarían sin ninguna sucursal.');
            }

            $this->database->table('sucursales')
                ->where('id', $branchId)
                ->where('empresa_id', $companyId)
                ->update([
                    ...$data,
                    'updated_at' => date('Y-m-d H:i:s'),
                    'updated_by' => $actorUserId,
                ]);
            $this->commitOrFail();
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }

    public function createUser(
        int $companyId,
        array $data,
        array $roleIds,
        array $branchIds,
        string $reason,
        int $actorUserId,
    ): int {
        $this->database->transBegin();

        try {
            $this->company($companyId);
            $this->assertUniqueEmail($data['email']);
            $branchIds = $this->validatedAccess($companyId, $roleIds, $branchIds);
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
                throw new RuntimeException('No se pudo crear el usuario.');
            }

            $this->replaceAccess($userId, $roleIds, $branchIds);
            $this->appendHistory(
                $userId,
                $companyId,
                'USUARIO_CREADO',
                [],
                ['nombre' => $data['nombre'], 'email' => $data['email'], 'roles' => $roleIds, 'sucursales' => $branchIds],
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

    public function updateUser(
        int $companyId,
        int $userId,
        array $data,
        string $reason,
        int $actorUserId,
    ): void {
        $this->database->transBegin();

        try {
            $user = $this->lockedTenantUser($companyId, $userId);
            $this->assertUniqueEmail($data['email'], $userId);
            $before = ['nombre' => $user['nombre'], 'email' => $user['email'], 'activo' => (int) $user['activo']];
            $this->database->table('usuarios')->where('id', $userId)->where('empresa_id', $companyId)->update([
                ...$data,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $actorUserId,
            ]);
            $this->appendHistory($userId, $companyId, 'USUARIO_ACTUALIZADO', $before, $data, $reason, $actorUserId);
            $this->commitOrFail();
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }

    public function assignUserAccess(
        int $companyId,
        int $userId,
        array $roleIds,
        array $branchIds,
        string $reason,
        int $actorUserId,
    ): void {
        $this->database->transBegin();

        try {
            $this->lockedTenantUser($companyId, $userId);
            $branchIds = $this->validatedAccess($companyId, $roleIds, $branchIds);
            $before = ['roles' => $this->roleIds($userId), 'sucursales' => $this->branchIds($userId)];
            $this->replaceAccess($userId, $roleIds, $branchIds);
            $this->appendHistory(
                $userId,
                $companyId,
                'ASIGNACION_ACCESO',
                $before,
                ['roles' => $roleIds, 'sucursales' => $branchIds],
                $reason,
                $actorUserId,
            );
            $this->commitOrFail();
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }

    public function resetUserPassword(
        int $companyId,
        int $userId,
        string $password,
        string $reason,
        int $actorUserId,
    ): void {
        $this->database->transBegin();

        try {
            $this->lockedTenantUser($companyId, $userId);
            $this->database->table('usuarios')->where('id', $userId)->where('empresa_id', $companyId)->update([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'updated_at'    => date('Y-m-d H:i:s'),
                'updated_by'    => $actorUserId,
            ]);
            $this->appendHistory(
                $userId,
                $companyId,
                'PASSWORD_RESTABLECIDA',
                [],
                ['restablecida' => true],
                $reason,
                $actorUserId,
            );
            $this->commitOrFail();
        } catch (Throwable $exception) {
            $this->database->transRollback();
            throw $exception;
        }
    }

    private function company(int $companyId): array
    {
        $company = $this->database->table('empresas')
            ->select('id, razon_social, nombre_fantasia, estado')
            ->where('id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->get()->getRowArray();
        if ($company === null) {
            throw new DomainException('La empresa no existe o está inactiva.');
        }

        return $company;
    }

    private function lockedBranch(int $companyId, int $branchId): array
    {
        $branch = $this->database->query(
            'SELECT id, estado FROM sucursales WHERE id = ? AND empresa_id = ? AND deleted_at IS NULL FOR UPDATE',
            [$branchId, $companyId],
        )->getRowArray();
        if ($branch === null) {
            throw new DomainException('La sucursal no existe dentro de tu empresa.');
        }

        return $branch;
    }

    private function lockedTenantUser(int $companyId, int $userId): array
    {
        $user = $this->database->query(
            'SELECT id, nombre, email, activo FROM usuarios '
            . 'WHERE id = ? AND empresa_id = ? AND es_superadmin = 0 AND deleted_at IS NULL FOR UPDATE',
            [$userId, $companyId],
        )->getRowArray();
        if ($user === null) {
            throw new DomainException('El usuario no existe dentro de tu empresa.');
        }

        return $user;
    }

    private function assertUniqueBranchCode(int $companyId, string $code, ?int $exceptBranchId = null): void
    {
        $builder = $this->database->table('sucursales')
            ->where('empresa_id', $companyId)
            ->where('codigo', $code)
            ->where('deleted_at', null);
        if ($exceptBranchId !== null) {
            $builder->where('id !=', $exceptBranchId);
        }
        if ($builder->countAllResults() > 0) {
            throw new DomainException('Ya existe una sucursal con ese código en tu empresa.');
        }
    }

    private function assertUniqueEmail(string $email, ?int $exceptUserId = null): void
    {
        $builder = $this->database->table('usuarios')->where('email', $email);
        if ($exceptUserId !== null) {
            $builder->where('id !=', $exceptUserId);
        }
        if ($builder->countAllResults() > 0) {
            throw new DomainException('Ya existe un usuario con ese email.');
        }
    }

    /** @return list<int> */
    private function validatedAccess(int $companyId, array $roleIds, array $branchIds): array
    {
        sort($roleIds);
        sort($branchIds);
        if ($roleIds === []) {
            throw new DomainException('El usuario debe tener al menos un rol.');
        }

        $roleRows = $this->database->table('roles')->select('id, nombre')->whereIn('id', $roleIds)->get()->getResultArray();
        $validRoleIds = array_map(static fn (array $row): int => (int) $row['id'], $roleRows);
        sort($validRoleIds);
        if ($validRoleIds !== $roleIds) {
            throw new DomainException('Uno o más roles seleccionados no existen.');
        }

        if (in_array('Administrador', array_column($roleRows, 'nombre'), true)) {
            return [];
        }
        if ($branchIds === []) {
            throw new DomainException('Un usuario restringido debe tener al menos una sucursal activa.');
        }

        $validBranchIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->table('sucursales')
                ->select('id')
                ->where('empresa_id', $companyId)
                ->where('estado', 1)
                ->where('deleted_at', null)
                ->whereIn('id', $branchIds)
                ->get()->getResultArray(),
        );
        sort($validBranchIds);
        if ($validBranchIds !== $branchIds) {
            throw new DomainException('Una o más sucursales no pertenecen a tu empresa o están inactivas.');
        }

        return $branchIds;
    }

    private function replaceAccess(int $userId, array $roleIds, array $branchIds): void
    {
        $this->database->table('usuario_roles')->where('usuario_id', $userId)->delete();
        $this->database->table('usuario_sucursales')->where('usuario_id', $userId)->delete();
        $now = date('Y-m-d H:i:s');
        foreach ($roleIds as $roleId) {
            $this->database->table('usuario_roles')->insert(['usuario_id' => $userId, 'rol_id' => $roleId, 'created_at' => $now]);
        }
        foreach ($branchIds as $branchId) {
            $this->database->table('usuario_sucursales')->insert(['usuario_id' => $userId, 'sucursal_id' => $branchId, 'created_at' => $now]);
        }
    }

    private function usersDependingOnBranch(int $companyId, int $branchId): int
    {
        $row = $this->database->query(
            'SELECT COUNT(DISTINCT u.id) total FROM usuarios u '
            . 'INNER JOIN usuario_sucursales target ON target.usuario_id = u.id AND target.sucursal_id = ? '
            . 'WHERE u.empresa_id = ? AND u.activo = 1 AND u.es_superadmin = 0 AND u.deleted_at IS NULL '
            . "AND NOT EXISTS (SELECT 1 FROM usuario_roles ur INNER JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = u.id AND r.nombre = 'Administrador') "
            . 'AND NOT EXISTS (SELECT 1 FROM usuario_sucursales other_access INNER JOIN sucursales other_branch ON other_branch.id = other_access.sucursal_id '
            . 'WHERE other_access.usuario_id = u.id AND other_branch.empresa_id = ? AND other_branch.estado = 1 '
            . 'AND other_branch.deleted_at IS NULL AND other_branch.id <> ?)',
            [$branchId, $companyId, $companyId, $branchId],
        )->getRowArray();

        return (int) ($row['total'] ?? 0);
    }

    /** @return list<int> */
    private function roleIds(int $userId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['rol_id'],
            $this->database->table('usuario_roles')->select('rol_id')->where('usuario_id', $userId)->orderBy('rol_id')->get()->getResultArray(),
        );
    }

    /** @return list<int> */
    private function branchIds(int $userId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['sucursal_id'],
            $this->database->table('usuario_sucursales')->select('sucursal_id')->where('usuario_id', $userId)->orderBy('sucursal_id')->get()->getResultArray(),
        );
    }

    /** @throws JsonException */
    private function appendHistory(
        int $userId,
        int $companyId,
        string $action,
        array $before,
        array $after,
        string $reason,
        int $actorUserId,
    ): void {
        $this->database->table('usuario_acceso_historial')->insert([
            'usuario_id'            => $userId,
            'empresa_id'            => $companyId,
            'accion'                => $action,
            'detalle_anterior'      => json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'detalle_nuevo'         => json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'motivo'                => $reason,
            'actor_usuario_id'      => $actorUserId,
            'created_at'            => date('Y-m-d H:i:s'),
        ]);
    }

    private function commitOrFail(): void
    {
        if (! $this->database->transStatus()) {
            throw new RuntimeException('La operación no pudo completarse.');
        }
        $this->database->transCommit();
    }
}
