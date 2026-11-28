<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * La página del QR la abre un chofer sin sesión: usa la hoja de estilos de la app, siempre en
 * tema claro, y no puede romperse si falta el build del frontend.
 */
final class PublicEquipmentPageDesignTest extends TestCase
{
    public function testLasPaginasPublicasUsanLaCabeceraComun(): void
    {
        foreach (['reading', 'invalid'] as $view) {
            $source = (string) file_get_contents(APPPATH . 'Views/public_equipment/' . $view . '.php');
            self::assertStringContainsString("view('partials/public_page_head'", $source, $view);
            self::assertStringNotContainsString('font-family:system-ui', $source, $view . ' no vuelve a los estilos sueltos.');
        }
    }

    public function testLaCabeceraEsClaraYToleraQueFalteElBuild(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Views/partials/public_page_head.php');

        self::assertStringContainsString('data-theme="light"', $source);
        self::assertStringContainsString("is_file(\$manifestPath)", $source);
        self::assertStringContainsString("assets/dashboard/' . \$stylesheet", $source);
        self::assertStringContainsString('vogel-consultoria-sin-fondo.png', $source);
    }

    public function testTailwindLeeLasVistasPublicas(): void
    {
        $config = (string) file_get_contents(ROOTPATH . 'frontend/tailwind.config.js');

        self::assertStringContainsString("'../app/Views/public_equipment/**/*.php'", $config);
        self::assertStringContainsString("'../app/Views/partials/public_page_head.php'", $config);
    }
}
