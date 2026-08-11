<?php

declare(strict_types=1);

namespace App\Application\Organization\Port;

interface TenantAdministrationPort
{
    /**
     * @return array{company: array<string, mixed>, branches: list<array<string, mixed>>}
     */
    public function branchesOverview(int $companyId, int $page, int $perPage): array;

    /**
     * @return array{company: array<string, mixed>, users: list<array<string, mixed>>, roles: list<array<string, mixed>>, branches: list<array<string, mixed>>}
     */
    public function usersOverview(int $companyId, int $page, int $perPage): array;

    /** @param array{codigo: string, nombre: string, direccion: string|null, email_alertas: string|null} $data */
    public function createBranch(int $companyId, array $data, int $actorUserId): int;

    /** @param array{codigo: string, nombre: string, direccion: string|null, email_alertas: string|null, estado: int} $data */
    public function updateBranch(int $companyId, int $branchId, array $data, int $actorUserId): void;

    /**
     * @param array{nombre: string, email: string, password: string} $data
     * @param list<int> $roleIds
     * @param list<int> $branchIds
     */
    public function createUser(
        int $companyId,
        array $data,
        array $roleIds,
        array $branchIds,
        string $reason,
        int $actorUserId,
    ): int;

    /** @param array{nombre: string, email: string, activo: int} $data */
    public function updateUser(
        int $companyId,
        int $userId,
        array $data,
        string $reason,
        int $actorUserId,
    ): void;

    /**
     * @param list<int> $roleIds
     * @param list<int> $branchIds
     */
    public function assignUserAccess(
        int $companyId,
        int $userId,
        array $roleIds,
        array $branchIds,
        string $reason,
        int $actorUserId,
    ): void;

    public function resetUserPassword(
        int $companyId,
        int $userId,
        string $password,
        string $reason,
        int $actorUserId,
    ): void;
}
