<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PwaAssetsTest extends TestCase
{
    public function testManifestHasInstallablePngIconsAndWorkerDoesNotCacheAuthenticatedPages(): void
    {
        $manifest = json_decode((string) file_get_contents(ROOTPATH . 'manifest.webmanifest'), true, 512, JSON_THROW_ON_ERROR);
        $icons = array_column($manifest['icons'], null, 'sizes');
        self::assertSame('Mantenimiento de equipos', $manifest['name']);
        self::assertSame('Mantenimiento', $manifest['short_name']);
        self::assertSame('./dashboard', $manifest['start_url']);
        self::assertSame('./', $manifest['scope']);
        self::assertSame('standalone', $manifest['display']);
        self::assertSame('#031A3E', $manifest['theme_color']);
        self::assertArrayHasKey('192x192', $icons);
        self::assertArrayHasKey('512x512', $icons);
        self::assertSame('image/png', $icons['192x192']['type']);
        self::assertSame('maskable', $icons['512x512']['purpose']);

        foreach ([192, 512] as $size) {
            $image = getimagesize(ROOTPATH . "assets/pwa/icon-{$size}.png");
            self::assertSame([$size, $size], [$image[0], $image[1]]);
        }

        $worker = (string) file_get_contents(ROOTPATH . 'service-worker.js');
        self::assertStringNotContainsString("addEventListener('fetch'", $worker);
        self::assertStringContainsString('candidate.origin === scope.origin', $worker);

        $main = (string) file_get_contents(ROOTPATH . 'frontend/src/main.js');
        self::assertStringContainsString('navigator.serviceWorker.register', $main);
        self::assertStringNotContainsString('Notification.requestPermission()', $main);
    }
}
