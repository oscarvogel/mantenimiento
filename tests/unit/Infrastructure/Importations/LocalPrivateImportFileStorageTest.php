<?php

declare(strict_types=1);

use App\Infrastructure\Importations\LocalPrivateImportFileStorage;
use PHPUnit\Framework\TestCase;

final class LocalPrivateImportFileStorageTest extends TestCase
{
    private string $root;
    private string $source;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'maintenance_import_' . bin2hex(random_bytes(5));
        $this->source = tempnam(sys_get_temp_dir(), 'maintenance_upload_') ?: self::fail('No temp file');
        file_put_contents($this->source, "codigo,fecha_alta\nCAM-1,2026-08-08\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->source) && is_file($this->source)) {
            unlink($this->source);
        }
        if (isset($this->root) && is_dir($this->root)) {
            foreach (glob($this->root . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($this->root);
        }
    }

    public function testStoresWithOpaqueNameAndDeletesOnlyManagedFile(): void
    {
        $storage = new LocalPrivateImportFileStorage($this->root);
        $stored = $storage->store($this->source, 'equipos.csv');

        self::assertFileExists($stored->path);
        self::assertStringStartsWith('import_', basename($stored->path));
        self::assertSame(hash_file('sha256', $this->source), $stored->sha256);

        $storage->delete($stored->path);
        self::assertFileDoesNotExist($stored->path);
    }

    public function testRetentionNeverDeletesProtectedActiveDraft(): void
    {
        $storage = new LocalPrivateImportFileStorage($this->root);
        $stored = $storage->store($this->source, 'equipos.csv');
        touch($stored->path, time() - (3 * 86400));

        self::assertSame(0, $storage->purgeOlderThan(1, [$stored->path]));
        self::assertFileExists($stored->path);
        self::assertSame(1, $storage->purgeOlderThan(1));
        self::assertFileDoesNotExist($stored->path);
    }

    public function testRejectsStorageInsideProjectRoot(): void
    {
        $this->expectException(DomainException::class);
        new LocalPrivateImportFileStorage(ROOTPATH . 'writable/importaciones');
    }

    public function testRejectsRelativeStorageRoot(): void
    {
        $this->expectException(DomainException::class);

        new LocalPrivateImportFileStorage('../private-imports');
    }
}
