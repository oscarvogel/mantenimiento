<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use App\Application\Dashboard\Port\GlobalDashboardReadModel;
use App\Application\Identity\ActorContext;
use DomainException;

final readonly class GetGlobalDashboard
{
    public function __construct(private GlobalDashboardReadModel $readModel)
    {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, ?int $companyId = null): array
    {
        if (! $actor->isSuperAdmin()) {
            throw new DomainException('El tablero global requiere una cuenta de Superadministrador.');
        }

        return $this->readModel->fetch($companyId);
    }
}
