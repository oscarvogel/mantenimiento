<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\DocumentImport;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\DocumentImport\Port\WorkOrderDocumentImportRepository;
use App\Application\WorkOrders\DocumentImport\Port\WorkOrderDocumentStorage;
use App\Domain\WorkOrders\WorkOrderDocumentImport;
use DateTimeImmutable;
use DomainException;
use finfo;
use Throwable;

final class UploadWorkOrderDocumentHandler
{
    public function __construct(
        private readonly WorkOrderDocumentStorage $storage,
        private readonly WorkOrderDocumentImportRepository $imports,
        private readonly int $maximumSizeBytes = 10_485_760,
    ) {}

    public function execute(ActorContext $actor, UploadWorkOrderDocumentCommand $command): UploadWorkOrderDocumentResult
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La importación de OT requiere una empresa activa.');
        }
        if (! $actor->hasPermission('ordenes.editar')) {
            throw new DomainException('No tenés permiso para importar órdenes de taller.');
        }
        if ($command->branchId <= 0) {
            throw new DomainException('La sucursal es obligatoria.');
        }
        if (! $actor->hasAllCompanyBranches() && ! in_array($command->branchId, $actor->branchIds(), true)) {
            throw new DomainException('No tenés acceso a la sucursal seleccionada.');
        }
        if (! is_file($command->temporaryPath)) {
            throw new DomainException('El documento temporal no está disponible.');
        }

        $size = filesize($command->temporaryPath);
        if ($size === false) {
            throw new DomainException('No se pudo determinar el tamaño del documento.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($command->temporaryPath);
        if (! is_string($mime)) {
            throw new DomainException('No se pudo determinar el tipo real del documento.');
        }
        $mime = strtolower($mime);
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => throw new DomainException('Solo se admiten documentos JPG, PNG o PDF.'),
        };
        $sha256 = hash_file('sha256', $command->temporaryPath);
        if ($sha256 === false) {
            throw new DomainException('No se pudo calcular la huella del documento.');
        }

        // Un duplicado exacto sólo puede reutilizarse dentro de la misma sucursal.
        // La sucursal seleccionada forma parte del contexto funcional de la importación:
        // reutilizar una importación de otra sucursal cambiaría silenciosamente el scope
        // y terminaría mostrando/resolviendo equipos que no corresponden.
        $duplicate = $this->imports->findBySha256($actor->companyId(), $command->branchId, $sha256);
        if ($duplicate !== null) {
            return new UploadWorkOrderDocumentResult($duplicate, true);
        }

        $idempotencyKey = $command->idempotencyKey;
        if ($this->imports->findByIdempotencyKey($actor->companyId(), $idempotencyKey) !== null) {
            // El mismo contenido de esta sucursal ya habría sido devuelto por SHA-256 arriba.
            // Si llegamos acá, la clave pertenece a otro archivo o a otra sucursal y debe
            // renovarse para no arrastrar el contexto anterior.
            $idempotencyKey = bin2hex(random_bytes(24));
        }

        $stored = $this->storage->store($command->temporaryPath, $actor->companyId(), $extension);
        try {
            $import = WorkOrderDocumentImport::create(
                companyId: $actor->companyId(),
                branchId: $command->branchId,
                createdBy: $actor->userId(),
                originalName: $command->originalName,
                storedName: $stored->storedName,
                privateRelativePath: $stored->privateRelativePath,
                mimeType: $mime,
                sizeBytes: (int) $size,
                sha256: $sha256,
                idempotencyKey: $idempotencyKey,
                createdAt: new DateTimeImmutable(),
                maxSizeBytes: $this->maximumSizeBytes,
            );
            return new UploadWorkOrderDocumentResult($this->imports->add($import), false);
        } catch (Throwable $exception) {
            $this->storage->delete($stored->privateRelativePath);
            throw $exception;
        }
    }
}
