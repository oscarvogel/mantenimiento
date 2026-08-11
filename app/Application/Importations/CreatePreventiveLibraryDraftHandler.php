<?php

declare(strict_types=1);

namespace App\Application\Importations;

use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\ImportRepository;
use App\Application\Importations\Port\PreventiveLibraryWorkbookReader;
use App\Application\Importations\Port\PrivateImportFileStorage;
use App\Domain\Importations\ImportRowStatus;
use App\Domain\Importations\ImportType;
use DomainException;
use Throwable;

final class CreatePreventiveLibraryDraftHandler
{
    public function __construct(
        private readonly PreventiveLibraryWorkbookReader $reader,
        private readonly PrivateImportFileStorage $files,
        private readonly ImportRepository $imports,
        private readonly PreventiveLibraryValidator $validator,
        private readonly int $maximumRows = 5000,
    ) {
    }

    public function execute(ActorContext $actor, CreateImportDraftCommand $command): CreateImportDraftResult
    {
        $companyId = $this->tenantCompany($actor);
        if (ImportType::parse($command->type) !== ImportType::BIBLIOTECA_PREVENTIVA) {
            throw new DomainException('El handler de biblioteca solo admite BIBLIOTECA_PREVENTIVA.');
        }

        $origin = trim($command->origin) ?: 'CARGA_WEB';
        $stored = $this->files->store($command->uploadedPath, $command->originalName);
        $importId = null;

        try {
            if (strtolower(pathinfo($stored->path, PATHINFO_EXTENSION)) !== 'xlsx') {
                throw new DomainException('La biblioteca preventiva requiere un archivo XLSX multihoja.');
            }

            $importId = $this->imports->create(
                $companyId,
                ImportType::BIBLIOTECA_PREVENTIVA,
                $stored->originalName,
                $stored->path,
                $stored->mediaType,
                $stored->sha256,
                $origin,
                $actor->userId(),
            );

            $sourceRows = $this->reader->read($stored->path, $this->maximumRows);
            $staged = $this->validator->validate($sourceRows, $actor, $companyId);
            $valid = $errors = $duplicates = 0;
            foreach ($staged as $item) {
                match ($item->status) {
                    ImportRowStatus::VALIDA => $valid++,
                    ImportRowStatus::ERROR => $errors++,
                    ImportRowStatus::DUPLICADA => $duplicates++,
                    default => null,
                };
            }

            foreach (array_chunk($staged, 200) as $chunk) {
                $this->imports->stageRows($importId, $chunk);
            }

            $summary = "Biblioteca preventiva: {$valid} filas validas, {$errors} con error y {$duplicates} duplicadas.";
            $this->imports->markValidated($importId, count($staged), $valid, $errors, $duplicates, $summary);

            return new CreateImportDraftResult($importId, count($staged), $valid, $errors, $duplicates);
        } catch (Throwable $exception) {
            if ($importId !== null) {
                $this->imports->markFailed($importId, $exception->getMessage());
            }
            $this->files->delete($stored->path);
            throw $exception;
        }
    }

    private function tenantCompany(ActorContext $actor): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La importacion de biblioteca requiere un usuario perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('importaciones.cargar')) {
            throw new DomainException('No tenes permiso para cargar la biblioteca preventiva.');
        }
        return $actor->companyId();
    }
}
