<?php

declare(strict_types=1);

namespace App\Application\Importations;

use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\ImportRepository;
use App\Application\Importations\Port\ImportUnitOfWork;
use App\Application\Importations\Port\PrivateImportFileStorage;
use App\Domain\Importations\ImportBatch;
use DomainException;

final class CancelImportHandler
{
    public function __construct(
        private readonly ImportRepository $imports,
        private readonly ImportUnitOfWork $unitOfWork,
        private readonly PrivateImportFileStorage $files,
    ) {
    }

    public function execute(ActorContext $actor, int $importId): void
    {
        $companyId = $this->tenantCompany($actor);
        $path = $this->unitOfWork->transactional(function () use ($actor, $companyId, $importId): string {
            $draft = $this->imports->findForUpdate(
                $importId, $companyId, $actor->userId(), $actor->hasAllCompanyBranches(),
            );
            if ($draft === null) {
                throw new DomainException('La importacion no existe o pertenece a otra empresa.');
            }
            $batch = new ImportBatch($draft->id, $draft->companyId, $draft->type, $draft->status);
            $batch->cancel();
            $this->imports->markCancelled($draft->id, $actor->userId(), 'Cancelada explicitamente sin persistir datos de destino.');
            return $draft->privatePath;
        });
        $this->files->delete($path);
    }

    private function tenantCompany(ActorContext $actor): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('importaciones.cargar')) {
            throw new DomainException('No tenes permiso para cancelar importaciones de una empresa.');
        }
        return $actor->companyId();
    }
}
