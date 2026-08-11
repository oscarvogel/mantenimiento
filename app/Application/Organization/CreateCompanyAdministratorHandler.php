<?php

declare(strict_types=1);

namespace App\Application\Organization;

use App\Application\Identity\ActorContext;
use App\Application\Organization\Port\OrganizationAdministrationPort;
use DomainException;

final class CreateCompanyAdministratorHandler
{
    public function __construct(private readonly OrganizationAdministrationPort $administration)
    {
    }

    public function execute(ActorContext $actor, CreateCompanyAdministratorCommand $command): int
    {
        if (! $actor->isSuperAdmin()) {
            throw new DomainException('La operaciÃ³n requiere acceso de Superadministrador.');
        }

        $name = trim($command->name);
        $email = mb_strtolower(trim($command->email));
        $reason = trim($command->reason);

        if ($command->companyId <= 0 || $name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('La empresa, el nombre y el email del administrador son obligatorios.');
        }
        if (mb_strlen($name) > 255 || mb_strlen($email) > 255) {
            throw new DomainException('El nombre y el email no pueden superar 255 caracteres.');
        }
        if (mb_strlen($command->password) < 8 || mb_strlen($command->password) > 255) {
            throw new DomainException('La contraseÃ±a debe tener al menos 8 caracteres.');
        }
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new DomainException('El motivo debe tener al menos 5 caracteres.');
        }

        return $this->administration->createCompanyAdministrator(
            $command->companyId,
            ['nombre' => $name, 'email' => $email, 'password' => $command->password],
            $reason,
            $actor->userId(),
        );
    }
}
