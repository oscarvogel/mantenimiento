<?php

declare(strict_types=1);

namespace App\Application\Importations;

use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\ImportRepository;
use DomainException;

final class GetImportPreviewHandler
{
    public function __construct(private readonly ImportRepository $imports)
    {
    }

    public function execute(ActorContext $actor, int $importId, int $page = 1, int $perPage = 50): ImportPreview
    {
        $companyId = $this->tenantCompany($actor);
        $preview = $this->imports->preview(
            $importId,
            $companyId,
            $actor->userId(),
            $actor->branchIds(),
            $actor->hasAllCompanyBranches(),
            max(1, $page),
            max(1, min(100, $perPage)),
        );
        if ($preview === null) {
            throw new DomainException('La importacion no existe o no esta autorizada para el actor.');
        }
        return $preview;
    }

    private function tenantCompany(ActorContext $actor): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('importaciones.ver')) {
            throw new DomainException('No tenes permiso para ver importaciones de una empresa.');
        }
        return $actor->companyId();
    }
}
