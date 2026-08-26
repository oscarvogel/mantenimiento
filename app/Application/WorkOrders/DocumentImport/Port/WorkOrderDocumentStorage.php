<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\DocumentImport\Port;

final readonly class StoredWorkOrderDocument
{
    public function __construct(
        public string $storedName,
        public string $privateRelativePath,
    ) {}
}

interface WorkOrderDocumentStorage
{
    public function store(string $temporaryPath, int $companyId, string $extension): StoredWorkOrderDocument;

    public function absolutePath(string $privateRelativePath): string;

    public function delete(string $privateRelativePath): void;
}
