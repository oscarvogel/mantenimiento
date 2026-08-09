<?php

declare(strict_types=1);

namespace App\Application\Organization;

use App\Application\Identity\ActorContext;
use App\Application\Organization\Port\OrganizationAdministrationPort;
use DomainException;

final class CreateCompanyHandler
{
    public function __construct(private readonly OrganizationAdministrationPort $administration)
    {
    }

    /**
     * @param array{razon_social: string, nombre_fantasia: string|null, cuit: string|null, email: string|null, telefono: string|null} $data
     */
    public function execute(ActorContext $actor, array $data): int
    {
        $this->requireSuperAdmin($actor);

        if (trim($data['razon_social']) === '') {
            throw new DomainException('La razón social es obligatoria.');
        }

        return $this->administration->createCompany($data, $actor->userId());
    }

    private function requireSuperAdmin(ActorContext $actor): void
    {
        if (! $actor->isSuperAdmin()) {
            throw new DomainException('La operación requiere acceso de Superadministrador.');
        }
    }
}
