<?php

declare(strict_types=1);

namespace Tests\Unit\Application\WorkOrders\DocumentImport;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\DocumentImport\UploadWorkOrderDocumentCommand;
use App\Application\WorkOrders\DocumentImport\UploadWorkOrderDocumentHandler;
use App\Application\WorkOrders\DocumentImport\Port\StoredWorkOrderDocument;
use App\Application\WorkOrders\DocumentImport\Port\WorkOrderDocumentImportRepository;
use App\Application\WorkOrders\DocumentImport\Port\WorkOrderDocumentStorage;
use App\Domain\WorkOrders\WorkOrderDocumentImport;
use PHPUnit\Framework\TestCase;

final class UploadWorkOrderDocumentHandlerTest extends TestCase
{
    public function testIdempotentRetryDoesNotStoreOrCreateASecondImport(): void
    {
        $storage = new InMemoryDocumentStorage();
        $imports = new InMemoryDocumentImportRepository();
        $handler = new UploadWorkOrderDocumentHandler($storage, $imports);
        $actor = new ActorContext(11, 3, false, true, ['Administrador'], ['ordenes.editar'], [7]);
        $command = new UploadWorkOrderDocumentCommand(
            branchId: 7,
            temporaryPath: dirname(__DIR__, 5) . '/assets/pwa/icon-192.png',
            originalName: 'orden-taller.png',
            idempotencyKey: 'ot-doc-retry-test',
        );

        $firstId = $handler->execute($actor, $command);
        $secondId = $handler->execute($actor, $command);

        self::assertSame($firstId, $secondId);
        self::assertSame(1, $storage->stores);
        self::assertCount(1, $imports->documents);
    }
}

final class InMemoryDocumentStorage implements WorkOrderDocumentStorage
{
    public int $stores = 0;

    public function store(string $temporaryPath, int $companyId, string $extension): StoredWorkOrderDocument
    {
        $this->stores++;
        $storedName = str_repeat('a', 48) . '.png';
        return new StoredWorkOrderDocument($storedName, $companyId . '/' . $storedName);
    }

    public function absolutePath(string $privateRelativePath): string
    {
        return $privateRelativePath;
    }

    public function delete(string $privateRelativePath): void
    {
    }
}

final class InMemoryDocumentImportRepository implements WorkOrderDocumentImportRepository
{
    /** @var list<WorkOrderDocumentImport> */
    public array $documents = [];

    public function add(WorkOrderDocumentImport $import): int
    {
        $this->documents[] = $import;
        return count($this->documents);
    }

    public function findForActor(int $importId, int $companyId, ?array $branchIds): ?array
    {
        return null;
    }

    public function saveAnalysis(int $importId, int $companyId, array $analysis, string $status, ?string $error = null): void
    {
    }

    public function saveProposal(int $importId, int $companyId, array $proposal): void
    {
    }

    public function findByIdempotencyKey(int $companyId, string $idempotencyKey): ?int
    {
        return $this->documents === [] ? null : 1;
    }

    public function linkWorkOrder(int $importId, int $companyId, int $workOrderId, string $kind): void
    {
    }
}
