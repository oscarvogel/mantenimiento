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
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = dirname(__DIR__, 5) . '/assets/pwa/icon-192.png';
    }

    public function testIdempotentRetryDoesNotStoreOrCreateASecondImport(): void
    {
        $storage = new InMemoryDocumentStorage();
        $imports = new InMemoryDocumentImportRepository();
        $handler = new UploadWorkOrderDocumentHandler($storage, $imports);
        $actor = $this->actor(11);
        $command = $this->command('ot-doc-retry-test');

        $first = $handler->execute($actor, $command);
        $second = $handler->execute($actor, $command);

        self::assertSame($first->importId, $second->importId);
        self::assertFalse($first->duplicateExact);
        self::assertTrue($second->duplicateExact);
        self::assertSame(1, $storage->stores);
        self::assertCount(1, $imports->documents);
    }

    public function testSameBytesWithDifferentIdempotencyKeyAreDetectedBySha256(): void
    {
        $storage = new InMemoryDocumentStorage();
        $imports = new InMemoryDocumentImportRepository();
        $handler = new UploadWorkOrderDocumentHandler($storage, $imports);
        $actor = $this->actor(11);

        $first = $handler->execute($actor, $this->command('first-upload'));
        $second = $handler->execute($actor, $this->command('second-upload', 'otro-nombre.png'));

        self::assertFalse($first->duplicateExact);
        self::assertTrue($second->duplicateExact);
        self::assertSame($first->importId, $second->importId);
        self::assertSame(1, $storage->stores);
        self::assertCount(1, $imports->documents);
    }

    public function testSameSha256DoesNotCollideAcrossCompanies(): void
    {
        $storage = new InMemoryDocumentStorage();
        $imports = new InMemoryDocumentImportRepository();
        $handler = new UploadWorkOrderDocumentHandler($storage, $imports);

        $first = $handler->execute($this->actor(11), $this->command('company-11'));
        $second = $handler->execute($this->actor(12), $this->command('company-12'));

        self::assertFalse($first->duplicateExact);
        self::assertFalse($second->duplicateExact);
        self::assertNotSame($first->importId, $second->importId);
        self::assertSame(2, $storage->stores);
        self::assertCount(2, $imports->documents);
    }

    private function actor(int $companyId): ActorContext
    {
        return new ActorContext(11, $companyId, false, true, ['Administrador'], ['ordenes.editar'], [7]);
    }

    private function command(string $key, string $name = 'orden-taller.png'): UploadWorkOrderDocumentCommand
    {
        return new UploadWorkOrderDocumentCommand(7, $this->fixture, $name, $key);
    }
}

final class InMemoryDocumentStorage implements WorkOrderDocumentStorage
{
    public int $stores = 0;

    public function store(string $temporaryPath, int $companyId, string $extension): StoredWorkOrderDocument
    {
        $this->stores++;
        $storedName = str_repeat(dechex(($this->stores % 15) + 1), 48) . '.' . $extension;
        return new StoredWorkOrderDocument($storedName, $companyId . '/' . $storedName);
    }

    public function absolutePath(string $privateRelativePath): string { return $privateRelativePath; }
    public function delete(string $privateRelativePath): void {}
}

final class InMemoryDocumentImportRepository implements WorkOrderDocumentImportRepository
{
    /** @var array<int,WorkOrderDocumentImport> */
    public array $documents = [];

    public function add(WorkOrderDocumentImport $import): int
    {
        $id = count($this->documents) + 1;
        $this->documents[$id] = $import;
        return $id;
    }

    public function findForActor(int $importId, int $companyId, ?array $branchIds): ?array { return null; }
    public function saveAnalysis(int $importId, int $companyId, array $analysis, string $status, ?string $error = null): void {}
    public function saveProposal(int $importId, int $companyId, array $proposal): void {}

    public function findByIdempotencyKey(int $companyId, string $idempotencyKey): ?int
    {
        foreach ($this->documents as $id => $document) {
            if ($document->companyId() === $companyId && $document->idempotencyKey() === $idempotencyKey) return $id;
        }
        return null;
    }

    public function findBySha256(int $companyId, string $sha256): ?int
    {
        foreach ($this->documents as $id => $document) {
            if ($document->companyId() === $companyId && $document->sha256() === $sha256) return $id;
        }
        return null;
    }

    public function linkWorkOrder(int $importId, int $companyId, int $workOrderId, string $kind): void {}
}
