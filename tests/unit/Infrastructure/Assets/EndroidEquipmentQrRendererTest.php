<?php

declare(strict_types=1);

use App\Infrastructure\Assets\EndroidEquipmentQrRenderer;
use PHPUnit\Framework\TestCase;

final class EndroidEquipmentQrRendererTest extends TestCase
{
    public function testRendersScannableSvgWithoutExternalFile(): void
    {
        $svg = (new EndroidEquipmentQrRenderer())->renderSvg('https://example.test/mantenimiento/equipos/10');

        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('</svg>', $svg);
        self::assertStringNotContainsString('<script', strtolower($svg));
    }
}
