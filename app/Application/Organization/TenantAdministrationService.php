<?php

declare(strict_types=1);

namespace App\Application\Organization;

use App\Application\Identity\ActorContext;
use App\Application\Organization\Port\TenantAdministrationPort;
use DomainException;

final class TenantAdministrationService
{
    public function __construct(private readonly TenantAdministrationPort $administration)
    {
    }

    public function branchesOverview(ActorContext $actor): array
    {
        return $this->administration->branchesOverview($this->companyFor($actor, 'sucursales.ver'));
    }

    public function usersOverview(ActorContext $actor): array
    {
        return $this->administration->usersOverview($this->companyFor($actor, 'usuarios.ver'));
    }

    public function createBranch(ActorContext $actor, array $data): int
    {
        $companyId = $this->companyFor($actor, 'sucursales.editar');
        $data = $this->normalizeBranch($data, false);

        return $this->administration->createBranch($companyId, $data, $actor->userId());
    }

    public function updateBranch(ActorContext $actor, int $branchId, array $data): void
    {
        if ($branchId <= 0) {
            throw new DomainException('La sucursal no es válida.');
        }

        $companyId = $this->companyFor($actor, 'sucursales.editar');
        $this->administration->updateBranch(
            $companyId,
            $branchId,
            $this->normalizeBranch($data, true),
            $actor->userId(),
        );
    }

    public function createUser(
        ActorContext $actor,
        array $data,
        array $roleIds,
        array $branchIds,
        string $reason,
    ): int {
        $companyId = $this->companyFor($actor, 'usuarios.editar');
        $this->requirePermission($actor, 'roles.editar');
        $data = $this->normalizeNewUser($data);
        $reason = $this->reason($reason);

        return $this->administration->createUser(
            $companyId,
            $data,
            $this->ids($roleIds),
            $this->ids($branchIds),
            $reason,
            $actor->userId(),
        );
    }

    public function updateUser(ActorContext $actor, int $userId, array $data, string $reason): void
    {
        if ($userId <= 0) {
            throw new DomainException('El usuario no es válido.');
        }

        $companyId = $this->companyFor($actor, 'usuarios.editar');
        $name = trim((string) ($data['nombre'] ?? ''));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $active = (int) ($data['activo'] ?? -1);

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! in_array($active, [0, 1], true)) {
            throw new DomainException('Los datos del usuario no son válidos.');
        }
        if ($userId === $actor->userId() && $active === 0) {
            throw new DomainException('No podés desactivar tu propia cuenta.');
        }

        $this->administration->updateUser(
            $companyId,
            $userId,
            ['nombre' => $name, 'email' => $email, 'activo' => $active],
            $this->reason($reason),
            $actor->userId(),
        );
    }

    public function assignUserAccess(
        ActorContext $actor,
        int $userId,
        array $roleIds,
        array $branchIds,
        string $reason,
    ): void {
        if ($userId <= 0) {
            throw new DomainException('El usuario no es válido.');
        }
        if ($userId === $actor->userId()) {
            throw new DomainException('No podés modificar tus propios roles o sucursales.');
        }

        $companyId = $this->companyFor($actor, 'usuarios.editar');
        $this->requirePermission($actor, 'roles.editar');
        $this->administration->assignUserAccess(
            $companyId,
            $userId,
            $this->ids($roleIds),
            $this->ids($branchIds),
            $this->reason($reason),
            $actor->userId(),
        );
    }

    public function resetUserPassword(
        ActorContext $actor,
        int $userId,
        string $password,
        string $reason,
    ): void {
        if ($userId <= 0 || mb_strlen($password) < 8) {
            throw new DomainException('La contraseña debe tener al menos 8 caracteres.');
        }

        $this->administration->resetUserPassword(
            $this->companyFor($actor, 'usuarios.editar'),
            $userId,
            $password,
            $this->reason($reason),
            $actor->userId(),
        );
    }

    private function companyFor(ActorContext $actor, string $permission): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('Esta operación requiere una cuenta perteneciente a una empresa.');
        }

        $this->requirePermission($actor, $permission);

        return $actor->companyId();
    }

    private function requirePermission(ActorContext $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new DomainException('No tenés permiso para realizar esta operación.');
        }
    }

    private function normalizeBranch(array $data, bool $withState): array
    {
        $normalized = [
            'codigo'        => mb_strtoupper(trim((string) ($data['codigo'] ?? ''))),
            'nombre'        => trim((string) ($data['nombre'] ?? '')),
            'direccion'     => $this->nullable($data['direccion'] ?? null),
            'email_alertas' => $this->nullable($data['email_alertas'] ?? null),
        ];

        if ($normalized['codigo'] === '' || $normalized['nombre'] === '') {
            throw new DomainException('El código y el nombre de la sucursal son obligatorios.');
        }
        if ($normalized['email_alertas'] !== null && ! filter_var($normalized['email_alertas'], FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('El email de alertas no es válido.');
        }

        if ($withState) {
            $state = (int) ($data['estado'] ?? -1);
            if (! in_array($state, [0, 1], true)) {
                throw new DomainException('El estado de la sucursal no es válido.');
            }
            $normalized['estado'] = $state;
        }

        return $normalized;
    }

    private function normalizeNewUser(array $data): array
    {
        $name = trim((string) ($data['nombre'] ?? ''));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('El nombre y un email válido son obligatorios.');
        }
        if (mb_strlen($password) < 8) {
            throw new DomainException('La contraseña debe tener al menos 8 caracteres.');
        }

        return ['nombre' => $name, 'email' => $email, 'password' => $password];
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new DomainException('El motivo debe tener entre 5 y 255 caracteres.');
        }

        return $reason;
    }

    /** @return list<int> */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
