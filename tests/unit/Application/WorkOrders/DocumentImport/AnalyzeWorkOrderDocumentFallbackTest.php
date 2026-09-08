<?php

declare(strict_types=1);

namespace Tests\Unit\Application\WorkOrders\DocumentImport;

use App\Application\WorkOrders\DocumentImport\AnalyzeWorkOrderDocument;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AnalyzeWorkOrderDocumentFallbackTest extends TestCase
{
    public function testUsesConceptAsCorrectiveFallbackWhenWorksAreMissing(): void
    {
        $work = $this->fallback([
            'concept' => 'Compra de repuesto (canilla pequeña para barril de 50 L)',
        ], [[
            'description' => 'Canilla pequeña para barril de 50 L',
            'confidence' => 0.95,
            'source_text' => 'TORNEIRA PEQUENA P/BARRICA 50LTS',
        ]]);

        self::assertNotNull($work);
        self::assertSame('correctivo', $work['classification']);
        self::assertSame('Compra de repuesto (canilla pequeña para barril de 50 L)', $work['description']);
        self::assertTrue($work['included']);
        self::assertTrue($work['fallback']);
    }

    public function testBuildsCorrectiveFallbackFromDetectedMaterialWhenConceptIsEmpty(): void
    {
        $work = $this->fallback(['concept' => null], [[
            'description' => 'Canilla pequeña para barril de 50 L',
            'confidence' => 0.9,
            'source_text' => 'TORNEIRA PEQUENA P/BARRICA 50LTS',
        ]]);

        self::assertNotNull($work);
        self::assertSame('correctivo', $work['classification']);
        self::assertSame('Compra / provisión de repuesto: Canilla pequeña para barril de 50 L', $work['description']);
        self::assertSame('TORNEIRA PEQUENA P/BARRICA 50LTS', $work['source_text']);
    }

    public function testDoesNotInventFallbackWithoutConceptOrMaterials(): void
    {
        self::assertNull($this->fallback(['concept' => '   '], []));
    }

    /** @param array<string,mixed> $analysis @param list<array<string,mixed>> $materials */
    private function fallback(array $analysis, array $materials): ?array
    {
        $method = new ReflectionMethod(AnalyzeWorkOrderDocument::class, 'fallbackCorrectiveWork');
        $method->setAccessible(true);

        $result = $method->invoke(null, $analysis, $materials);

        return is_array($result) ? $result : null;
    }
}
