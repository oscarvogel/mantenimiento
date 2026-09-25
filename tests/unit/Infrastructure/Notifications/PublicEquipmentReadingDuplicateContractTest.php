<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PublicEquipmentReadingDuplicateContractTest extends TestCase
{
    public function testPublicReadingEndsInTerminalSuccessAndBlocksRapidDuplicate(): void
    {
        $controller = file_get_contents(APPPATH . 'Controllers/PublicEquipmentReadings.php');
        $view = file_get_contents(APPPATH . 'Views/public_equipment/reading.php');

        self::assertIsString($controller);
        self::assertIsString($view);

        self::assertStringContainsString("?registrada=1", $controller);
        self::assertStringContainsString("time() - 120", $controller);
        self::assertStringContainsString("'origen', 'QR_ANONIMO'", $controller);
        self::assertStringContainsString("'referencia_origen', 'PUBLIC_TOKEN#' . \$tokenId", $controller);
        self::assertStringContainsString("'registered' => $registered", $controller);
        self::assertStringContainsString("'registered_title' => 'Lectura registrada'", $controller);
        self::assertStringContainsString("'registered_title' => 'Leitura registrada'", $controller);

        self::assertStringContainsString("if (! empty($registered))", $view);
        self::assertStringContainsString("Ya podés cerrar esta ventana", $view);
        self::assertStringContainsString("id=\"reading-submit\"", $view);
        self::assertStringContainsString("button.disabled = true", $view);
    }
}
