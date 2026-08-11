<?php

declare(strict_types=1);

namespace App\Application\Organization\Port;

interface OrganizationAdministrationPort
{
    /**
     * @return array{
     *     companies: list<array<string, mixed>>,
     *     users: list<array<string, mixed>>,
     *     roles: list<array<string, mixed>>
     * }
     */
    public function overview(int $companiesPage, int $companiesPerPage, int $usersPage, int $usersPerPage): array;

    /**
     * @param array{razon_social: string, nombre_fantasia: string|null, cuit: string|null, email: string|null, telefono: string|null} $data
     */
    public function createCompany(array $data, int $actorUserId): int;

    /**
     * @param array{nombre: string, email: string, password: string} $data
     */
    public function createCompanyAdministrator(
        int $companyId,
        array $data,
        string $reason,
        int $actorUserId,
    ): int;

    /**
     * @param array{razon_social: string, nombre_fantasia: string|null, cuit: string|null, email: string|null, telefono: string|null, estado: int} $data
     */
    public function updateCompany(int $companyId, array $data, int $actorUserId): void;

    public function assignUserToCompany(int $userId, int $companyId, string $reason, int $actorUserId): void;

    /**
     * @param list<int> $roleIds
     */
    public function assignRolesToUser(int $userId, array $roleIds, string $reason, int $actorUserId): void;
}
