<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AppShellAssetScriptTest extends TestCase
{
    public function testModuleEntryHasNoQueryStringThatCouldLoadTheEntryTwice(): void
    {
        foreach (['app.php', 'dashboard.php'] as $view) {
            $contents = file_get_contents(APPPATH . 'Views/' . $view);
            self::assertIsString($contents);
            self::assertStringNotContainsString('?v=', $contents, $view);
            self::assertStringContainsString(
                'assets/dashboard/\' . $entry[\'file\']',
                $contents,
                $view . ' debe seguir cargando el entry del manifest.',
            );
        }
    }

    /** Sin esto la transición entre páginas funde la anterior en una página vacía. */
    public function testEntryBlocksFirstRenderFromTheHead(): void
    {
        $contents = (string) file_get_contents(APPPATH . 'Views/app.php');
        $head     = explode('</head>', $contents, 2)[0];

        self::assertMatchesRegularExpression('/<script type="module" blocking="render" src=/', $head);
        self::assertSame(1, substr_count($contents, '<script type="module"'));
    }
}
