<?php

declare(strict_types=1);

namespace App\Application\Organization;

use App\Application\Identity\ActorContext;
use App\Application\Organization\Port\OrganizationAdministrationPort;
use DomainException;

final class AssignUserRolesHandler
{
    public function __construct(private readonly OrganizationAdministrationPort $administration)
    {
    }

    /**
     * @param list<int> $roleIds
     */
    public function execute(ActorContext $actor, int $userId, array $roleIds, string $reason): void
    {
        if (! $actor->isSuperAdmin()) {
            throw new DomainException('La operación requiere acceso de Superadministrador.');
        }

        $roleIds = array_values(array_unique(array_filter($roleIds, static fn (int $id): bool => $id > 0)));

        if ($userId <= 0 || $roleIds === []) {
            throw new DomainException('El usuario y al menos un rol son obligatorios.');
        }

        if (mb_strlen(trim($reason)) < 5) {
            throw new DomainException('El motivo debe tener al menos 5 caracteres.');
        }

        $this->administration->assignRolesToUser($userId, $roleIds, trim($reason), $actor->userId());
    }
}
