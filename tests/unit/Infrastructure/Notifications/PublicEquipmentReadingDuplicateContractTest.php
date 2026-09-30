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
        self::assertStringContainsString("'registered' => \$registered", $controller);
        self::assertStringContainsString("'registered_title' => 'Lectura registrada'", $controller);
        self::assertStringContainsString("'registered_title' => 'Leitura registrada'", $controller);
        self::assertStringContainsString('¿Cuántos kilómetros marca ahora el tablero?', $controller);
        self::assertStringContainsString('Ejemplo: si el tablero muestra 494497, escribí 494497.', $controller);
        self::assertStringContainsString('Quantos quilômetros o painel mostra agora?', $controller);

        self::assertStringContainsString("if (! empty(\$registered))", $view);
        self::assertStringContainsString("Ya podés cerrar esta ventana", $view);
        self::assertStringContainsString("id=\"reading-submit\"", $view);
        self::assertStringContainsString("button.disabled = true", $view);
        self::assertStringContainsString("current_km_help", $view);
    }
}
