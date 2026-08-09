<?php

declare(strict_types=1);

use App\Infrastructure\Diagnostics\PlatformDiagnostics;
use CodeIgniter\Test\CIUnitTestCase;

final class PlatformDiagnosticsTest extends CIUnitTestCase
{
    public function testSupportedPhpRuntimePasses(): void
    {
        $result = (new PlatformDiagnostics())->checkPhpVersion();

        $this->assertSame('PASS', $result['status'], $result['detail']);
    }

    public function testRequiredExtensionsAreLoaded(): void
    {
        $results = (new PlatformDiagnostics())->checkRequiredExtensions();

        foreach ($results as $result) {
            $this->assertSame('PASS', $result['status'], $result['name'] . ': ' . $result['detail']);
        }
    }

    public function testWritableDirectoriesSupportRoundTrip(): void
    {
        $results = (new PlatformDiagnostics())->checkWritableDirectories();

        foreach ($results as $result) {
            $this->assertSame('PASS', $result['status'], $result['name'] . ': ' . $result['detail']);
        }
    }

    public function testFileSessionStorageIsWritable(): void
    {
        $result = (new PlatformDiagnostics())->checkSessionStorage();

        $this->assertSame('PASS', $result['status'], $result['detail']);
    }
}
