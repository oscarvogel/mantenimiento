<?php

declare(strict_types=1);

namespace App\Application\Identity;

use InvalidArgumentException;

final class ActorContext
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     * @param list<int>    $branchIds
     */
    public function __construct(
        private readonly int $userId,
        private readonly ?int $companyId,
        private readonly bool $superAdmin,
        private readonly bool $allCompanyBranches,
        private readonly array $roles,
        private readonly array $permissions,
        private readonly array $branchIds,
    ) {
        if ($userId <= 0) {
            throw new InvalidArgumentException('El usuario del contexto debe ser válido.');
        }

        if ($superAdmin && $companyId !== null) {
            throw new InvalidArgumentException('Un Superadministrador global no pertenece a una empresa.');
        }

        if (! $superAdmin && ($companyId === null || $companyId <= 0)) {
            throw new InvalidArgumentException('Un usuario común debe pertenecer a una empresa.');
        }
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function companyId(): ?int
    {
        return $this->companyId;
    }

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    public function hasAllCompanyBranches(): bool
    {
        return $this->allCompanyBranches;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function canAccessCompany(int $companyId): bool
    {
        return $this->superAdmin || $this->companyId === $companyId;
    }

    public function canAccessBranch(int $companyId, int $branchId): bool
    {
        if (! $this->canAccessCompany($companyId)) {
            return false;
        }

        return $this->superAdmin
            || $this->allCompanyBranches
            || in_array($branchId, $this->branchIds, true);
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    /**
     * @return list<int>
     */
    public function branchIds(): array
    {
        return $this->branchIds;
    }

    /**
     * @return array{
     *     user_id: int,
     *     company_id: int|null,
     *     super_admin: bool,
     *     all_company_branches: bool,
     *     roles: list<string>,
     *     permissions: list<string>,
     *     branch_ids: list<int>
     * }
     */
    public function toArray(): array
    {
        return [
            'user_id'              => $this->userId,
            'company_id'           => $this->companyId,
            'super_admin'          => $this->superAdmin,
            'all_company_branches' => $this->allCompanyBranches,
            'roles'                => array_values(array_unique($this->roles)),
            'permissions'          => array_values(array_unique($this->permissions)),
            'branch_ids'           => array_values(array_unique($this->branchIds)),
        ];
    }

    /**
     * @param array{
     *     user_id: int,
     *     company_id: int|null,
     *     super_admin: bool,
     *     all_company_branches: bool,
     *     roles: list<string>,
     *     permissions: list<string>,
     *     branch_ids: list<int>
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['user_id'],
            $data['company_id'],
            $data['super_admin'],
            $data['all_company_branches'],
            $data['roles'],
            $data['permissions'],
            $data['branch_ids'],
        );
    }
}
