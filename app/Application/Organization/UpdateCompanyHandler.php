<?php

declare(strict_types=1);

namespace App\Application\Organization;

use App\Application\Identity\ActorContext;
use App\Application\Organization\Port\OrganizationAdministrationPort;
use DomainException;

final class UpdateCompanyHandler
{
    public function __construct(private readonly OrganizationAdministrationPort $administration)
    {
    }

    /**
     * @param array{razon_social: string, nombre_fantasia: string|null, cuit: string|null, email: string|null, telefono: string|null, estado: int} $data
     */
    public function execute(ActorContext $actor, int $companyId, array $data): void
    {
        if (! $actor->isSuperAdmin()) {
            throw new DomainException('La operación requiere acceso de Superadministrador.');
        }

        if ($companyId <= 0 || trim($data['razon_social']) === '' || ! in_array($data['estado'], [0, 1], true)) {
            throw new DomainException('Los datos de la empresa no son válidos.');
        }

        $this->administration->updateCompany($companyId, $data, $actor->userId());
    }
}
