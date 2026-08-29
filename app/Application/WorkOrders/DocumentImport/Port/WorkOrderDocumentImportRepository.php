<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\DocumentImport\Port;

use App\Domain\WorkOrders\WorkOrderDocumentImport;

interface WorkOrderDocumentImportRepository
{
    public function add(WorkOrderDocumentImport $import): int;

    /** @return array<string,mixed>|null */
    public function findForActor(int $importId, int $companyId, ?array $branchIds): ?array;

    /** @param array<string,mixed> $analysis */
    public function saveAnalysis(int $importId, int $companyId, array $analysis, string $status, ?string $error = null): void;

    /** @param array<string,mixed> $proposal */
    public function saveProposal(int $importId, int $companyId, array $proposal): void;

    public function findByIdempotencyKey(int $companyId, string $idempotencyKey): ?int;

    public function findBySha256(int $companyId, int $branchId, string $sha256): ?int;

    public function linkWorkOrder(int $importId, int $companyId, int $workOrderId, string $kind): void;
}
