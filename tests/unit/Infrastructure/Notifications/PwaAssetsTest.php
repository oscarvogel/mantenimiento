<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PwaAssetsTest extends TestCase
{
    public function testManifestHasInstallablePngIconsAndWorkerDoesNotCacheAuthenticatedPages(): void
    {
        $manifest = json_decode((string) file_get_contents(ROOTPATH . 'manifest.webmanifest'), true, 512, JSON_THROW_ON_ERROR);
        $icons = array_column($manifest['icons'], null, 'sizes');
        self::assertArrayHasKey('192x192', $icons);
        self::assertArrayHasKey('512x512', $icons);
        self::assertSame('image/png', $icons['192x192']['type']);

        foreach ([192, 512] as $size) {
            $image = getimagesize(ROOTPATH . "assets/pwa/icon-{$size}.png");
            self::assertSame([$size, $size], [$image[0], $image[1]]);
        }

        $worker = (string) file_get_contents(ROOTPATH . 'service-worker.js');
        self::assertStringNotContainsString("addEventListener('fetch'", $worker);
        self::assertStringContainsString('candidate.origin === scope.origin', $worker);
    }
}
