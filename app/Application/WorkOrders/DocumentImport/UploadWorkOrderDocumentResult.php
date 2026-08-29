<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\DocumentImport;

final readonly class UploadWorkOrderDocumentResult
{
    public function __construct(
        public int $importId,
        public bool $duplicateExact,
    ) {}
}
