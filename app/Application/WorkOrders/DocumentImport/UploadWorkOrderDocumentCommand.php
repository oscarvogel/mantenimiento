<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\DocumentImport;

final readonly class UploadWorkOrderDocumentCommand
{
    public function __construct(
        public int $branchId,
        public string $temporaryPath,
        public string $originalName,
        public string $idempotencyKey,
    ) {}
}
