<?php

declare(strict_types=1);

namespace App\Application\Importations;

use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\ImportRepository;
use DomainException;

final class ListImportsHandler
{
    public function __construct(private readonly ImportRepository $imports)
    {
    }

    public function execute(ActorContext $actor, int $page = 1, int $perPage = 20): ImportHistoryPage
    {
        $companyId = $this->tenantCompany($actor);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        return $this->imports->history(
            $companyId,
            $actor->userId(),
            $actor->branchIds(),
            $actor->hasAllCompanyBranches(),
            $page,
            $perPage,
        );
    }

    private function tenantCompany(ActorContext $actor): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('importaciones.ver')) {
            throw new DomainException('No tenes permiso para ver importaciones de una empresa.');
        }
        return $actor->companyId();
    }
}
