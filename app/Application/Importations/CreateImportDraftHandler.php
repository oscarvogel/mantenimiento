<?php

declare(strict_types=1);

namespace App\Application\Importations;

use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\ImportRepository;
use App\Application\Importations\Port\PrivateImportFileStorage;
use App\Application\Importations\Port\SpreadsheetReader;
use App\Domain\Importations\ImportRowStatus;
use App\Domain\Importations\ImportType;
use DomainException;
use Throwable;

final class CreateImportDraftHandler
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly PrivateImportFileStorage $files,
        private readonly ImportRepository $imports,
        private readonly ImportRowValidator $validator,
        private readonly int $maximumRows = 5000,
        /** @var array<string, SpreadsheetReader> */
        private readonly array $readersByType = [],
    ) {
    }

    public function execute(ActorContext $actor, CreateImportDraftCommand $command): CreateImportDraftResult
    {
        $companyId = $this->tenantCompany($actor, 'importaciones.cargar');
        $type = ImportType::parse($command->type);
        $origin = trim($command->origin) ?: 'CARGA_WEB';
        if (mb_strlen($origin) > 100) {
            throw new DomainException('El origen de la importacion admite hasta 100 caracteres.');
        }
        $stored = $this->files->store($command->uploadedPath, $command->originalName);
        $importId = null;

        try {
            $importId = $this->imports->create(
                $companyId, $type, $stored->originalName, $stored->path, $stored->mediaType,
                $stored->sha256, $origin, $actor->userId(),
            );
            $reader = $this->readersByType[$type->value] ?? $this->reader;
            $sheet = $reader->read($stored->path, $this->maximumRows);
            $this->validateHeaders($type, $sheet->headers);
            if ($sheet->rows === []) {
                throw new DomainException('El archivo no contiene filas de datos.');
            }

            $this->validator->beginFile();
            $staged = [];
            $valid = $errors = $duplicates = 0;
            foreach ($sheet->rows as $index => $row) {
                $item = $this->validator->validate($type, $row, $index + 2, $actor, $companyId);
                $staged[] = $item;
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
            $summary = "Vista previa: {$valid} validas, {$errors} con error y {$duplicates} duplicadas.";
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

    /** @param list<string> $headers */
    private function validateHeaders(ImportType $type, array $headers): void
    {
        if (count($headers) !== count(array_unique($headers))) {
            throw new DomainException('El archivo contiene encabezados repetidos.');
        }
        $missing = array_values(array_diff($type->requiredHeaders(), $headers));
        if ($missing !== []) {
            throw new DomainException('Faltan encabezados obligatorios: ' . implode(', ', $missing) . '.');
        }
    }

    private function tenantCompany(ActorContext $actor, string $permission): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La operacion requiere un actor perteneciente a una empresa.');
        }
        if (! $actor->hasPermission($permission)) {
            throw new DomainException('No tenes permiso para gestionar importaciones.');
        }
        return $actor->companyId();
    }
}
