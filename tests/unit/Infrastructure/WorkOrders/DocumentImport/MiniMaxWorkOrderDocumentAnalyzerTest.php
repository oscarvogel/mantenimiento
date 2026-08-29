<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\WorkOrders\DocumentImport;

use App\Infrastructure\WorkOrders\DocumentImport\MiniMaxWorkOrderDocumentAnalyzer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MiniMaxWorkOrderDocumentAnalyzerTest extends TestCase
{
    public function testReportsMissingApiKeyBeforeAttemptingNetworkCall(): void
    {
        $analyzer = new MiniMaxWorkOrderDocumentAnalyzer(
            apiKey: '',
            endpoint: 'http://127.0.0.1:9/v1/chat/completions',
        );

        $this->expectExceptionMessage('Falta configurar la API key de MiniMax.');

        $analyzer->analyze(__FILE__, 'image/png');
    }

    public function testRemovesMinimaxThinkingBlockBeforeDecodingJson(): void
    {
        $analyzer = new MiniMaxWorkOrderDocumentAnalyzer('dummy');
        $method = new ReflectionMethod($analyzer, 'decodeJson');

        $result = $method->invoke($analyzer, "<think>Reasoning hidden from the caller.</think>\n{\"ok\":true}");

        self::assertSame(['ok' => true], $result);
    }

    public function testPromptRequiresLanguageDetectionAndSpanishTextOutput(): void
    {
        $analyzer = new MiniMaxWorkOrderDocumentAnalyzer('dummy');
        $method = new ReflectionMethod($analyzer, 'prompt');

        $prompt = (string) $method->invoke($analyzer);

        self::assertStringContainsString('source_language', $prompt);
        self::assertStringContainsString('español o portugués', $prompt);
        self::assertStringContainsString('TODOS los campos textuales destinados al sistema deben quedar redactados en ESPAÑOL', $prompt);
        self::assertStringContainsString('source_text conservá literalmente el texto original', $prompt);
        self::assertStringContainsString('No traduzcas ni alteres patente, marca, modelo', $prompt);
    }

    public function testHydratesPortugueseSourceLanguageAndKeepsSpanishNormalizedDescriptions(): void
    {
        $analyzer = new MiniMaxWorkOrderDocumentAnalyzer('dummy');
        $method = new ReflectionMethod($analyzer, 'hydrate');

        $analysis = $method->invoke($analyzer, [
            'source_language' => 'pt-BR',
            'plate' => 'ABC1D23',
            'brand' => 'Mercedes-Benz',
            'model' => 'Actros 2546',
            'service_date' => '2026-08-29',
            'reading_type' => 'km',
            'reading_value' => 125300,
            'supplier' => 'Oficina São José',
            'concept' => 'Cambio de bomba de agua y correa',
            'observations' => 'Se verificó una pérdida de refrigerante',
            'total_amount' => 813382,
            'currency' => 'ARS',
            'works' => [[
                'description' => 'Cambio de bomba de agua',
                'classification' => 'correctivo',
                'quantity' => 1,
                'unit' => 'unidad',
                'confidence' => 0.98,
                'source_text' => "Troca da bomba d'água",
            ]],
            'materials' => [[
                'description' => 'Correa',
                'quantity' => 1,
                'unit' => 'unidad',
                'confidence' => 0.95,
                'source_text' => 'Correia',
            ]],
            'confidence' => ['plate' => 0.99],
        ]);

        self::assertSame('pt', $analysis->sourceLanguage);
        self::assertSame('ABC1D23', $analysis->plate);
        self::assertSame('Mercedes-Benz', $analysis->brand);
        self::assertSame('Actros 2546', $analysis->model);
        self::assertSame(125300.0, $analysis->readingValue);
        self::assertSame('Cambio de bomba de agua y correa', $analysis->concept);
        self::assertSame('Cambio de bomba de agua', $analysis->works[0]['description']);
        self::assertSame("Troca da bomba d'água", $analysis->works[0]['source_text']);
        self::assertSame('Correa', $analysis->materials[0]['description']);
        self::assertSame('Correia', $analysis->materials[0]['source_text']);
    }

    public function testNormalizesSpanishLanguageAliases(): void
    {
        $analyzer = new MiniMaxWorkOrderDocumentAnalyzer('dummy');
        $method = new ReflectionMethod($analyzer, 'normalizeLanguage');

        self::assertSame('es', $method->invoke($analyzer, 'es-AR'));
        self::assertSame('es', $method->invoke($analyzer, 'Español'));
        self::assertSame('pt', $method->invoke($analyzer, 'Português'));
    }
}
