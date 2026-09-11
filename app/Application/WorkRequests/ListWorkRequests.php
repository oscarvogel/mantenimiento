<?php

declare(strict_types=1);

namespace App\Application\WorkRequests;

use App\Application\Identity\ActorContext;
use App\Application\WorkRequests\Port\WorkRequestRepository;
use DomainException;

final readonly class ListWorkRequests
{
    public function __construct(private WorkRequestRepository $repository)
    {
    }

    /** @param array<string,mixed> $filters */
    public function execute(ActorContext $actor, array $filters, int $page = 1, int $perPage = 25): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('Las solicitudes requieren un usuario perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('solicitudes.crear') && ! $actor->hasPermission('solicitudes.revisar')) {
            throw new DomainException('No tenés permiso para consultar solicitudes.');
        }

        return $this->repository->listScoped(
            $actor->companyId(),
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
            $filters,
            max(1, $page),
            in_array($perPage, [10, 25, 50], true) ? $perPage : 25,
            $actor->hasPermission('solicitudes.revisar') ? null : $actor->userId(),
        );
    }
}
