<?php

declare(strict_types=1);

use App\Infrastructure\Importations\LocalPrivateImportFileStorage;
use PHPUnit\Framework\TestCase;

final class LocalPrivateImportFileStorageTest extends TestCase
{
    private string $root;
    private string $source;
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'maintenance_import_' . bin2hex(random_bytes(5));
        $this->source = tempnam(sys_get_temp_dir(), 'maintenance_upload_') ?: self::fail('No temp file');
        file_put_contents($this->source, "codigo,fecha_alta\nCAM-1,2026-08-08\n");
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
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

    public function testStoresValidZipPackage(): void
    {
        $package = $this->zipWith([
            'manifest.json' => '{"version":1}',
            'data/t05_viajes.csv' => "numero_viaje,km_salida\n1,100\n",
        ]);

        $storage = new LocalPrivateImportFileStorage($this->root);
        $stored = $storage->store($package, 'transporte-viajes.zip');

        self::assertFileExists($stored->path);
        self::assertStringEndsWith('.zip', $stored->path);
        self::assertSame('transporte-viajes.zip', $stored->originalName);
        self::assertSame(hash_file('sha256', $package), $stored->sha256);
        self::assertGreaterThan(0, $stored->size);
        self::assertNotSame('', $stored->mediaType);
    }

    public function testRejectsZipWithTraversalEntry(): void
    {
        $package = $this->zipWith(['../escape.csv' => "a,b\n1,2\n"]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ruta de archivo no permitida');

        (new LocalPrivateImportFileStorage($this->root))->store($package, 'paquete.zip');
    }

    public function testRejectsZipWithAbsoluteEntryPath(): void
    {
        $package = $this->zipWith(['/tmp/escape.csv' => "a,b\n1,2\n"]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ruta de archivo no permitida');

        (new LocalPrivateImportFileStorage($this->root))->store($package, 'paquete.zip');
    }

    public function testRejectsEmptyZipPackage(): void
    {
        $package = tempnam(sys_get_temp_dir(), 'maintenance_empty_zip_') ?: self::fail('No temp file');
        $this->temporaryFiles[] = $package;
        file_put_contents($package, "PK\x05\x06" . str_repeat("\0", 18));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no contiene archivos');

        (new LocalPrivateImportFileStorage($this->root))->store($package, 'paquete.zip');
    }

    public function testRejectsZipThatClaimsTooMuchUncompressedContent(): void
    {
        $package = $this->zipWith(['data.csv' => str_repeat('x', 2_048)]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('supera el limite permitido');

        (new LocalPrivateImportFileStorage($this->root, 10_485_760, 1_024))->store($package, 'paquete.zip');
    }

    public function testRejectsZipExtensionWhenContentIsNotCompressed(): void
    {
        $text = tempnam(sys_get_temp_dir(), 'maintenance_fake_zip_') ?: self::fail('No temp file');
        $this->temporaryFiles[] = $text;
        file_put_contents($text, "esto no es un zip");

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no coincide con su contenido');

        (new LocalPrivateImportFileStorage($this->root))->store($text, 'paquete.zip');
    }

    public function testRejectsZipRenamedAsCsv(): void
    {
        $package = $this->zipWith(['data.csv' => "a,b\n1,2\n"]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no coincide con su contenido');

        (new LocalPrivateImportFileStorage($this->root))->store($package, 'equipos.csv');
    }

    public function testRejectsCorruptZipPackage(): void
    {
        $corrupt = tempnam(sys_get_temp_dir(), 'maintenance_corrupt_zip_') ?: self::fail('No temp file');
        $this->temporaryFiles[] = $corrupt;
        file_put_contents($corrupt, "PK\x03\x04" . str_repeat("\0", 32));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('dañado');

        (new LocalPrivateImportFileStorage($this->root))->store($corrupt, 'paquete.zip');
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

    /** @param array<string, string> $entries */
    private function zipWith(array $entries): string
    {
        if (! class_exists(ZipArchive::class)) {
            self::markTestSkipped('La extension PHP zip no esta disponible.');
        }
        $path = tempnam(sys_get_temp_dir(), 'maintenance_package_') ?: self::fail('No temp file');
        $this->temporaryFiles[] = $path;
        $archive = new ZipArchive();
        self::assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            self::assertTrue($archive->addFromString($name, $contents));
        }
        self::assertTrue($archive->close());

        return $path;
    }
}
