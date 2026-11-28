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

    /** Los mensajes del flujo de la foto salen del catálogo, en los dos idiomas. */
    public function testPhotoFlowMessagesExistInBothLanguages(): void
    {
        $controller = (new ReflectionClass(\App\Controllers\PublicEquipmentReadings::class))->newInstanceWithoutConstructor();
        $call       = static fn (string $method, string ...$args) => (new ReflectionMethod($controller, $method))->invoke($controller, ...$args);

        $es = $call('labels', 'ES');
        $pt = $call('labels', 'PT');
        self::assertSame(array_keys($es), array_keys($pt), 'Cada etiqueta tiene su traducción.');

        $view = (string) file_get_contents(APPPATH . 'Views/public_equipment/reading.php');
        preg_match_all('/\btext\.(\w+)/', $view, $used);
        self::assertNotEmpty($used[1]);
        foreach (array_unique($used[1]) as $key) {
            self::assertArrayHasKey($key, $pt, "El JS usa text.{$key} y falta en las etiquetas.");
        }

        foreach (['ai_lower', 'ai_unreadable', 'photo_retake', 'photo_required', 'photo_size', 'photo_type'] as $key) {
            self::assertNotSame($call('tr', 'ES', $key), $call('tr', 'PT', $key), "{$key} está traducido.");
            self::assertNotSame($key, $call('tr', 'PT', $key), "{$key} existe en portugués.");
        }
        self::assertSame('Preparando foto...', $pt['preparing']);
        self::assertStringContainsString('hodômetro', $pt['not_readable']);
    }
}
