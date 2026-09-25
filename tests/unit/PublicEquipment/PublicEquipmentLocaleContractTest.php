<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PublicEquipmentLocaleContractTest extends TestCase
{
    public function testPublicReadingUsesCompanyLocaleAndPortugueseCopy(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/PublicEquipmentReadings.php');
        $reading = file_get_contents(APPPATH . 'Views/public_equipment/reading.php');
        $invalid = file_get_contents(APPPATH . 'Views/public_equipment/invalid.php');

        self::assertIsString($controller);
        self::assertIsString($reading);
        self::assertIsString($invalid);

        self::assertStringContainsString('co.idioma_notificaciones', $controller);
        self::assertStringContainsString("'PT'", $controller);
        self::assertStringContainsString('Quantos quilômetros o painel mostra agora?', $controller);
        self::assertStringContainsString('Exemplo: se o painel mostra 494497, digite 494497.', $controller);
        self::assertStringContainsString('Registrar leitura', $controller);
        self::assertStringContainsString('Leitura registrada com sucesso', $controller);
        self::assertStringContainsString("\$labels['current_km']", $reading);
        self::assertStringContainsString("\$labels['submit']", $reading);
        self::assertStringContainsString('$title', $invalid);
    }
}
