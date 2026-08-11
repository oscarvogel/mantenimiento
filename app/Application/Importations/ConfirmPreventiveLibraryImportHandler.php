<?php

declare(strict_types=1);

namespace App\Application\Importations;

use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\ImportRepository;
use App\Application\Importations\Port\ImportUnitOfWork;
use App\Application\Importations\Port\PreventiveLibraryDestinationGateway;
use App\Application\Importations\Port\PrivateImportFileStorage;
use App\Domain\Importations\ImportBatch;
use App\Domain\Importations\ImportType;
use DomainException;

final class ConfirmPreventiveLibraryImportHandler
{
    public function __construct(
        private readonly ImportRepository $imports,
        private readonly PreventiveLibraryDestinationGateway $destination,
        private readonly ImportUnitOfWork $unitOfWork,
        private readonly PrivateImportFileStorage $files,
        private readonly int $batchSize = 200,
    ) {
    }

    public function execute(ActorContext $actor, int $importId): ConfirmImportResult
    {
        $companyId = $this->tenantCompany($actor);
        $privatePath = '';
        $result = $this->unitOfWork->transactional(function () use ($actor, $companyId, $importId, &$privatePath): ConfirmImportResult {
            $draft = $this->imports->findForUpdate($importId, $companyId, $actor->userId(), $actor->hasAllCompanyBranches());
            if ($draft === null) {
                throw new DomainException('La importacion no existe o pertenece a otra empresa.');
            }
            if ($draft->type !== ImportType::BIBLIOTECA_PREVENTIVA) {
                throw new DomainException('La importacion seleccionada no corresponde a una biblioteca preventiva.');
            }

            $batch = new ImportBatch($draft->id, $draft->companyId, $draft->type, $draft->status);
            $batch->confirm();
            $privatePath = $draft->privatePath;

            $imported = $errors = $duplicates = 0;
            do {
                $rows = $this->imports->pendingRows($draft->id, 0, $this->batchSize);
                foreach ($rows as $row) {
                    try {
                        $destinationId = $this->destination->apply($companyId, $actor->userId(), $row['datos_normalizados']);
                        $this->imports->markRowImported($row['id'], $destinationId);
                        $imported++;
                    } catch (DomainException $exception) {
                        $this->imports->markRowError($row['id'], $exception->getMessage());
                        $errors++;
                    }
                }
            } while (count($rows) === $this->batchSize);

            $summary = "Biblioteca confirmada: {$imported} filas aplicadas, {$errors} con error y {$duplicates} duplicadas omitidas.";
            $this->imports->markConfirmed($draft->id, $actor->userId(), $imported, $errors, $duplicates, $summary);

            return new ConfirmImportResult($draft->id, $imported, $errors, $duplicates);
        });

        if ($privatePath !== '') {
            $this->files->delete($privatePath);
        }
        return $result;
    }

    private function tenantCompany(ActorContext $actor): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('importaciones.cargar')) {
            throw new DomainException('No tenes permiso para confirmar la biblioteca preventiva de una empresa.');
        }
        return $actor->companyId();
    }
}
