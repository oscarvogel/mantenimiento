<?php

declare(strict_types=1);

use App\Infrastructure\Assets\Attachment\FileinfoEquipmentAttachmentInspector;
use App\Infrastructure\Assets\Attachment\LocalPrivateAttachmentStorage;
use PHPUnit\Framework\TestCase;

final class PrivateEquipmentAttachmentStorageTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mantenimiento-adjuntos-' . bin2hex(random_bytes(8));
        mkdir($this->sandbox, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->sandbox);
        parent::tearDown();
    }

    public function testStoresWithOpaqueNameAndReadsOnlyRelativePrivatePath(): void
    {
        $source = $this->sandbox . DIRECTORY_SEPARATOR . 'source.txt';
        file_put_contents($source, 'private-content');
        $storage = new LocalPrivateAttachmentStorage($this->sandbox . DIRECTORY_SEPARATOR . 'private-root');

        $stored = $storage->store($source, 5, 'pdf');

        self::assertMatchesRegularExpression('/^[a-f0-9]{48}\.pdf$/', $stored->storedName);
        self::assertSame('5/' . $stored->storedName, $stored->privateRelativePath);
        self::assertSame('private-content', $storage->read($stored->privateRelativePath));

        $storage->delete($stored->privateRelativePath);
        $this->expectException(RuntimeException::class);
        $storage->read($stored->privateRelativePath);
    }

    /** @dataProvider traversalProvider */
    public function testRejectsTraversalAndUntrustedPaths(string $path): void
    {
        $storage = new LocalPrivateAttachmentStorage($this->sandbox . DIRECTORY_SEPARATOR . 'private-root');

        $this->expectException(RuntimeException::class);
        $storage->read($path);
    }

    /** @return iterable<string, array{string}> */
    public static function traversalProvider(): iterable
    {
        yield 'parent traversal' => ['5/../../secret.pdf'];
        yield 'windows traversal' => ['5\\..\\secret.pdf'];
        yield 'absolute path' => ['C:\\secret.pdf'];
        yield 'predictable name' => ['5/manual.pdf'];
        yield 'unknown extension' => ['5/' . str_repeat('a', 48) . '.php'];
    }

    public function testFileinfoDetectsRealPngMimeInsteadOfTrustingFilename(): void
    {
        $source = $this->sandbox . DIRECTORY_SEPARATOR . 'misleading.php';
        file_put_contents(
            $source,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
        );

        $inspected = (new FileinfoEquipmentAttachmentInspector())->inspect($source);

        self::assertSame('image/png', $inspected->mimeType);
        self::assertGreaterThan(0, $inspected->size);
    }

    public function testRejectsConfiguredStorageInsideFlatPublicProject(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fuera del directorio');

        new LocalPrivateAttachmentStorage(ROOTPATH . 'private-unsafe');
    }

    public function testRejectsRelativeStorageRoot(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ruta absoluta');

        new LocalPrivateAttachmentStorage('../private-unsafe');
    }

    private function removeSandbox(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeSandbox($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
