<?php

declare(strict_types=1);

namespace App\Application\Organization;

use App\Application\Identity\ActorContext;
use App\Application\Organization\Port\OrganizationAdministrationPort;
use DomainException;

final class GetOrganizationOverview
{
    public function __construct(private readonly OrganizationAdministrationPort $administration)
    {
    }

    /**
     * @return array{companies: list<array<string, mixed>>, users: list<array<string, mixed>>, roles: list<array<string, mixed>>}
     */
    public function execute(
        ActorContext $actor,
        int $companiesPage = 1,
        int $companiesPerPage = 10,
        int $usersPage = 1,
        int $usersPerPage = 10,
    ): array
    {
        if (! $actor->isSuperAdmin()) {
            throw new DomainException('La operación requiere acceso de Superadministrador.');
        }

        return $this->administration->overview($companiesPage, $companiesPerPage, $usersPage, $usersPerPage);
    }
}
