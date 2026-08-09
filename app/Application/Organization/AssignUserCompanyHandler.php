<?php

declare(strict_types=1);

namespace App\Application\Organization;

use App\Application\Identity\ActorContext;
use App\Application\Organization\Port\OrganizationAdministrationPort;
use DomainException;

final class AssignUserCompanyHandler
{
    public function __construct(private readonly OrganizationAdministrationPort $administration)
    {
    }

    public function execute(ActorContext $actor, int $userId, int $companyId, string $reason): void
    {
        if (! $actor->isSuperAdmin()) {
            throw new DomainException('La operación requiere acceso de Superadministrador.');
        }

        if ($userId <= 0 || $companyId <= 0) {
            throw new DomainException('El usuario y la empresa son obligatorios.');
        }

        if (mb_strlen(trim($reason)) < 5) {
            throw new DomainException('El motivo debe tener al menos 5 caracteres.');
        }

        $this->administration->assignUserToCompany($userId, $companyId, trim($reason), $actor->userId());
    }
}
