<?php

declare(strict_types=1);

use App\Application\Assets\Attachment\DownloadPrimaryEquipmentPhotoHandler;
use App\Application\Assets\Attachment\InspectedAttachmentFile;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentClock;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentEquipmentScope;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentFileInspector;
use App\Application\Assets\Attachment\Port\EquipmentAttachmentStorage;
use App\Application\Assets\Attachment\Port\PrimaryEquipmentPhotoRepository;
use App\Application\Assets\Attachment\Port\PrimaryPhotoProcessor;
use App\Application\Assets\Attachment\PrimaryEquipmentPhoto;
use App\Application\Assets\Attachment\ProcessedPhotoThumbnail;
use App\Application\Assets\Attachment\StoredAttachmentFile;
use App\Application\Assets\Attachment\UploadPrimaryEquipmentPhotoCommand;
use App\Application\Assets\Attachment\UploadPrimaryEquipmentPhotoHandler;
use App\Application\Identity\ActorContext;
use App\Domain\Assets\EquipmentAttachment;
use PHPUnit\Framework\TestCase;

final class PrimaryEquipmentPhotoHandlersTest extends TestCase
{
    public function testReplacesPrimaryPhotoInsideTenantScopeAndKeepsThumbnailOptional(): void
    {
        $repository = new PrimaryPhotoRepositoryFake4a();
        $storage = new PrimaryPhotoStorageFake4a();
        $handler = new UploadPrimaryEquipmentPhotoHandler(
            new PrimaryPhotoInspectorFake4a('image/webp', 700),
            $storage,
            new PrimaryPhotoScopeFake4a(7),
            new PrimaryPhotoClockFake4a(),
            $repository,
            new PrimaryPhotoProcessorFake4a(null),
            1024,
        );

        $id = $handler->execute(
            $this->actor(['equipos.editar']),
            new UploadPrimaryEquipmentPhotoCommand(9, 'temp-image', 'camion.webp'),
        );

        self::assertSame(51, $id);
        self::assertSame(5, $repository->photo?->companyId());
        self::assertSame(9, $repository->photo?->equipmentId());
        self::assertSame('FOTO_PRINCIPAL', $repository->photo?->type());
        self::assertNull($repository->thumbnail);
    }

    public function testRejectsPdfBeforeWritingPrivateStorage(): void
    {
        $storage = new PrimaryPhotoStorageFake4a();
        $handler = new UploadPrimaryEquipmentPhotoHandler(
            new PrimaryPhotoInspectorFake4a('application/pdf', 700),
            $storage,
            new PrimaryPhotoScopeFake4a(7),
            new PrimaryPhotoClockFake4a(),
            new PrimaryPhotoRepositoryFake4a(),
            new PrimaryPhotoProcessorFake4a(null),
        );

        $this->expectException(DomainException::class);
        try {
            $handler->execute($this->actor(['equipos.editar']), new UploadPrimaryEquipmentPhotoCommand(9, 'temp', 'manual.pdf'));
        } finally {
            self::assertSame(0, $storage->stores);
        }
    }

    public function testPrivateDownloadFallsBackToOriginalWhenThereIsNoThumbnail(): void
    {
        $repository = new PrimaryPhotoRepositoryFake4a();
        $repository->found = new PrimaryEquipmentPhoto(
            51, 9, 'camion.webp', '5/original.webp', 'image/webp', 700, null, null, null,
        );
        $handler = new DownloadPrimaryEquipmentPhotoHandler($repository, new PrimaryPhotoStorageFake4a());

        $download = $handler->execute($this->actor(['equipos.ver']), 9, true);

        self::assertSame('image/webp', $download->mimeType);
        self::assertSame('private:5/original.webp', $download->content);
        self::assertSame([7], $repository->lastBranches);
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions): ActorContext
    {
        return new ActorContext(29, 5, false, false, ['Responsable'], $permissions, [7]);
    }
}

final readonly class PrimaryPhotoInspectorFake4a implements EquipmentAttachmentFileInspector
{
    public function __construct(private string $mime, private int $size) {}
    public function inspect(string $temporaryPath): InspectedAttachmentFile { return new InspectedAttachmentFile($this->mime, $this->size); }
}

final class PrimaryPhotoStorageFake4a implements EquipmentAttachmentStorage
{
    public int $stores = 0;
    public function store(string $sourcePath, int $companyId, string $extension): StoredAttachmentFile
    {
        ++$this->stores;
        $name = str_repeat((string) min(9, $this->stores), 48) . '.' . $extension;
        return new StoredAttachmentFile($name, $companyId . '/' . $name);
    }
    public function read(string $privateRelativePath): string { return 'private:' . $privateRelativePath; }
    public function delete(string $privateRelativePath): void {}
}

final readonly class PrimaryPhotoScopeFake4a implements EquipmentAttachmentEquipmentScope
{
    public function __construct(private ?int $branchId) {}
    public function currentBranchId(int $companyId, int $equipmentId, ?array $authorizedBranchIds): ?int { return $this->branchId; }
}

final readonly class PrimaryPhotoClockFake4a implements EquipmentAttachmentClock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-08-12 10:00:00'); }
}

final readonly class PrimaryPhotoProcessorFake4a implements PrimaryPhotoProcessor
{
    public function __construct(private ?ProcessedPhotoThumbnail $thumbnail) {}
    public function createThumbnail(string $sourcePath, string $mimeType): ?ProcessedPhotoThumbnail { return $this->thumbnail; }
}

final class PrimaryPhotoRepositoryFake4a implements PrimaryEquipmentPhotoRepository
{
    public ?EquipmentAttachment $photo = null;
    public ?StoredAttachmentFile $thumbnail = null;
    public ?PrimaryEquipmentPhoto $found = null;
    public ?array $lastBranches = null;
    public function replace(EquipmentAttachment $photo, ?StoredAttachmentFile $thumbnail, ?string $thumbnailMimeType, ?int $thumbnailSize): int
    {
        $this->photo = $photo;
        $this->thumbnail = $thumbnail;
        return 51;
    }
    public function findScoped(int $companyId, int $equipmentId, ?array $authorizedBranchIds): ?PrimaryEquipmentPhoto
    {
        $this->lastBranches = $authorizedBranchIds;
        return $this->found;
    }
    public function retire(int $companyId, int $equipmentId, ?array $authorizedBranchIds, int $actorUserId, DateTimeImmutable $when, string $reason): bool { return true; }
}
