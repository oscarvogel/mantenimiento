<?php

declare(strict_types=1);

use App\Application\Assets\Attachment\DownloadedEquipmentAttachment;
use App\Application\Assets\Attachment\DownloadEquipmentAttachmentHandler;
use App\Application\Assets\Attachment\DownloadEquipmentAttachmentQuery;
use App\Application\Assets\Attachment\EquipmentAttachmentPage;
use App\Application\Assets\Attachment\InspectedAttachmentFile;
use App\Application\Assets\Attachment\ListEquipmentAttachmentsHandler;
use App\Application\Assets\Attachment\ListEquipmentAttachmentsQuery;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentClock;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentEquipmentScope;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentFileInspector;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentReadModel;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentRepository;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentStorage;
use App\Application\Assets\Attachment\RetireEquipmentAttachmentCommand;
use App\Application\Assets\Attachment\RetireEquipmentAttachmentHandler;
use App\Application\Assets\Attachment\StoredAttachmentFile;
use App\Application\Assets\Attachment\UploadEquipmentAttachmentCommand;
use App\Application\Assets\Attachment\UploadEquipmentAttachmentHandler;
use App\Application\Identity\ActorContext;
use App\Domain\Assets\EquipmentAttachment;
use PHPUnit\Framework\TestCase;

final class EquipmentAttachmentHandlersTest extends TestCase
{
    public function testUploadsAfterTenantScopeAndRealFileValidation(): void
    {
        $repository = new AttachmentRepositoryFake2d();
        $storage = new AttachmentStorageFake2d();
        $handler = new UploadEquipmentAttachmentHandler(
            new AttachmentInspectorFake2d('application/pdf', 600),
            $storage,
            $repository,
            new AttachmentEquipmentScopeFake2d(7),
            new AttachmentClockFake2d(),
            1024,
        );

        $id = $handler->execute(
            $this->actor(['equipos.editar'], [7]),
            new UploadEquipmentAttachmentCommand(9, 'temporary-file', 'manual.pdf', 'MANUAL'),
        );

        self::assertSame(41, $id);
        self::assertSame(5, $repository->added?->companyId());
        self::assertSame(7, $repository->added?->branchSnapshotId());
        self::assertSame('5/' . str_repeat('c', 48) . '.pdf', $repository->added?->privateRelativePath());
        self::assertFalse($storage->deleted);
    }

    public function testRejectsSpoofedExecutableBeforeWritingStorage(): void
    {
        $storage = new AttachmentStorageFake2d();
        $handler = new UploadEquipmentAttachmentHandler(
            new AttachmentInspectorFake2d('application/x-dosexec', 600),
            $storage,
            new AttachmentRepositoryFake2d(),
            new AttachmentEquipmentScopeFake2d(7),
            new AttachmentClockFake2d(),
        );

        try {
            $handler->execute(
                $this->actor(['equipos.editar'], [7]),
                new UploadEquipmentAttachmentCommand(9, 'temporary-file', 'informe.pdf', 'OTRO'),
            );
            self::fail('La carga ejecutable debía rechazarse.');
        } catch (DomainException) {
            self::assertFalse($storage->stored);
        }
    }

    public function testDeletesStoredFileWhenMetadataPersistenceFails(): void
    {
        $repository = new AttachmentRepositoryFake2d();
        $repository->failAdd = true;
        $storage = new AttachmentStorageFake2d();
        $handler = new UploadEquipmentAttachmentHandler(
            new AttachmentInspectorFake2d('application/pdf', 600),
            $storage,
            $repository,
            new AttachmentEquipmentScopeFake2d(7),
            new AttachmentClockFake2d(),
        );

        try {
            $handler->execute(
                $this->actor(['equipos.editar'], [7]),
                new UploadEquipmentAttachmentCommand(9, 'temporary-file', 'manual.pdf', 'MANUAL'),
            );
            self::fail('La persistencia debía fallar.');
        } catch (RuntimeException) {
            self::assertTrue($storage->deleted);
        }
    }

    public function testDoesNotUploadEquipmentOutsideCurrentBranchScope(): void
    {
        $storage = new AttachmentStorageFake2d();
        $handler = new UploadEquipmentAttachmentHandler(
            new AttachmentInspectorFake2d('application/pdf', 600),
            $storage,
            new AttachmentRepositoryFake2d(),
            new AttachmentEquipmentScopeFake2d(null),
            new AttachmentClockFake2d(),
        );

        $this->expectException(DomainException::class);
        try {
            $handler->execute(
                $this->actor(['equipos.editar'], [7]),
                new UploadEquipmentAttachmentCommand(9, 'temporary-file', 'manual.pdf', 'MANUAL'),
            );
        } finally {
            self::assertFalse($storage->stored);
        }
    }

    public function testListsPaginatedAttachmentsWithCurrentBranchScope(): void
    {
        $readModel = new AttachmentReadModelFake2d();
        $handler = new ListEquipmentAttachmentsHandler($readModel);

        $page = $handler->execute(
            $this->actor(['equipos.ver'], [7]),
            new ListEquipmentAttachmentsQuery(9, 2, 15),
        );

        self::assertSame(2, $page->page);
        self::assertSame([7], $readModel->branchIds);
        self::assertSame(9, $readModel->equipmentId);
    }

    public function testDownloadsOnlyAnActiveScopedAttachment(): void
    {
        $repository = new AttachmentRepositoryFake2d();
        $repository->found = $this->attachment();
        $storage = new AttachmentStorageFake2d();
        $handler = new DownloadEquipmentAttachmentHandler($repository, $storage);

        $download = $handler->execute(
            $this->actor(['equipos.ver'], [7]),
            new DownloadEquipmentAttachmentQuery(9, 31),
        );

        self::assertInstanceOf(DownloadedEquipmentAttachment::class, $download);
        self::assertSame('private-content', $download->content);
        self::assertSame([7], $repository->lastBranchIds);
    }

    public function testRetiresMetadataButPreservesPhysicalFile(): void
    {
        $repository = new AttachmentRepositoryFake2d();
        $repository->found = $this->attachment();
        $handler = new RetireEquipmentAttachmentHandler($repository, new AttachmentClockFake2d());

        $handler->execute(
            $this->actor(['equipos.editar'], [7]),
            new RetireEquipmentAttachmentCommand(9, 31, 'Documento desactualizado'),
        );

        self::assertSame('Documento desactualizado', $repository->retired?->retirementReason());
        self::assertSame(29, $repository->retired?->retiredBy());
    }

    public function testRejectsDownloadWithoutReadPermissionBeforeAccessingStorage(): void
    {
        $repository = new AttachmentRepositoryFake2d();
        $repository->found = $this->attachment();
        $storage = new AttachmentStorageFake2d();
        $handler = new DownloadEquipmentAttachmentHandler($repository, $storage);

        $this->expectException(DomainException::class);
        $handler->execute(
            $this->actor([], [7]),
            new DownloadEquipmentAttachmentQuery(9, 31),
        );
    }

    public function testRejectsGlobalSuperadministratorWithoutTenantContext(): void
    {
        $handler = new ListEquipmentAttachmentsHandler(new AttachmentReadModelFake2d());
        $globalActor = new ActorContext(1, null, true, true, ['Superadministrador'], ['equipos.ver'], []);

        $this->expectException(DomainException::class);
        $handler->execute($globalActor, new ListEquipmentAttachmentsQuery(9));
    }

    private function attachment(): EquipmentAttachment
    {
        return EquipmentAttachment::reconstitute(
            31,
            5,
            9,
            7,
            'MANUAL',
            'manual.pdf',
            str_repeat('c', 48) . '.pdf',
            '5/' . str_repeat('c', 48) . '.pdf',
            'application/pdf',
            600,
            null,
            29,
            new DateTimeImmutable('2026-08-08 09:00:00'),
            null,
            null,
            null,
        );
    }

    /** @param list<string> $permissions @param list<int> $branches */
    private function actor(array $permissions, array $branches): ActorContext
    {
        return new ActorContext(29, 5, false, false, ['Responsable'], $permissions, $branches);
    }
}

final readonly class AttachmentInspectorFake2d implements EquipmentAttachmentFileInspector
{
    public function __construct(private string $mime, private int $size) {}
    public function inspect(string $temporaryPath): InspectedAttachmentFile
    {
        return new InspectedAttachmentFile($this->mime, $this->size);
    }
}

final class AttachmentStorageFake2d implements EquipmentAttachmentStorage
{
    public bool $stored = false;
    public bool $deleted = false;
    public function store(string $sourcePath, int $companyId, string $extension): StoredAttachmentFile
    {
        $this->stored = true;
        $name = str_repeat('c', 48) . '.' . $extension;
        return new StoredAttachmentFile($name, $companyId . '/' . $name);
    }
    public function read(string $privateRelativePath): string { return 'private-content'; }
    public function delete(string $privateRelativePath): void { $this->deleted = true; }
}

final class AttachmentRepositoryFake2d implements EquipmentAttachmentRepository
{
    public bool $failAdd = false;
    public ?EquipmentAttachment $added = null;
    public ?EquipmentAttachment $found = null;
    public ?EquipmentAttachment $retired = null;
    public ?array $lastBranchIds = null;
    public function add(EquipmentAttachment $attachment): int
    {
        if ($this->failAdd) { throw new RuntimeException('DB failure'); }
        $this->added = $attachment;
        return 41;
    }
    public function findActiveScoped(int $companyId, int $equipmentId, int $attachmentId, ?array $authorizedBranchIds): ?EquipmentAttachment
    {
        $this->lastBranchIds = $authorizedBranchIds;
        return $this->found;
    }
    public function saveRetirement(EquipmentAttachment $attachment): void { $this->retired = $attachment; }
}

final readonly class AttachmentEquipmentScopeFake2d implements EquipmentAttachmentEquipmentScope
{
    public function __construct(private ?int $branchId) {}
    public function currentBranchId(int $companyId, int $equipmentId, ?array $authorizedBranchIds): ?int
    {
        return $this->branchId;
    }
}

final class AttachmentReadModelFake2d implements EquipmentAttachmentReadModel
{
    public ?array $branchIds = null;
    public int $equipmentId = 0;
    public function forEquipment(int $companyId, int $equipmentId, ?array $authorizedBranchIds, int $page, int $perPage): EquipmentAttachmentPage
    {
        $this->branchIds = $authorizedBranchIds;
        $this->equipmentId = $equipmentId;
        return new EquipmentAttachmentPage([], 0, $page, $perPage);
    }
}

final class AttachmentClockFake2d implements EquipmentAttachmentClock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-08-08 12:00:00'); }
}
