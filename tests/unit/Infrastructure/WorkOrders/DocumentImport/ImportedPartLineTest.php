<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\WorkOrders\DocumentImport;

use App\Domain\WorkOrders\ImportedPartLine;
use PHPUnit\Framework\TestCase;

/**
 * Comportamiento de la normalización de repuestos detectados en un documento,
 * sin base de datos ni framework. La carpeta es la asignada a este trabajo; la
 * ubicación natural de esta clase sería `tests/unit/Domain/WorkOrders/`.
 */
final class ImportedPartLineTest extends TestCase
{
    public function testWholeAndDecimalQuantitiesBecomeDecimalStrings(): void
    {
        self::assertSame('4.000', $this->line(['description' => 'Filtro de aceite', 'quantity' => 4])->quantity());
        self::assertSame('4.000', $this->line(['description' => 'Filtro de aceite', 'quantity' => '4'])->quantity());
        self::assertSame('1.500', $this->line(['description' => 'Filtro de aceite', 'quantity' => '1,5'])->quantity());
        self::assertSame('1.500', $this->line(['description' => 'Filtro de aceite', 'quantity' => 1.5])->quantity());
    }

    public function testConfirmedLineIsNotPendingAndKeepsTheDeclaredUnit(): void
    {
        $line = $this->line(['description' => 'Filtro de aceite', 'quantity' => 4, 'unit' => 'u']);

        self::assertSame(ImportedPartLine::STATUS_CONFIRMED, $line->status());
        self::assertFalse($line->needsHumanReview());
        self::assertNull($line->reviewReason());
        self::assertSame('u', $line->unit());
        self::assertSame('Filtro de aceite x 4 u', $line->summary());
    }

    /** @dataProvider unusableQuantities */
    public function testUnusableQuantityIsRegisteredAsPendingInsteadOfInventingANumber(mixed $quantity): void
    {
        $line = $this->line(['description' => 'Correa de distribución', 'quantity' => $quantity]);

        self::assertTrue($line->needsHumanReview(), 'La línea debe quedar pendiente de revisión humana.');
        self::assertSame('0.000', $line->quantity());
        self::assertNotNull($line->reviewReason());
        self::assertStringContainsString('cantidad válida', (string) $line->reviewReason());
        self::assertStringContainsString('cantidad a revisar', $line->summary());
    }

    /** @return iterable<string, array{mixed}> */
    public static function unusableQuantities(): iterable
    {
        yield 'ausente' => [null];
        yield 'vacío' => [''];
        yield 'texto' => ['varios'];
        yield 'cero' => [0];
        yield 'negativa' => [-2];
        yield 'absurda' => [ImportedPartLine::MAX_QUANTITY + 1];
        yield 'redondea a cero' => [0.0001];
    }

    public function testOversizedDescriptionIsTruncatedAndFlaggedWithoutLosingTheOriginalText(): void
    {
        $long = str_repeat('Filtro de aceite de altísimo caudal para motor ', 10);
        $line = $this->line(['description' => $long, 'quantity' => 2, 'source_text' => $long]);

        self::assertSame(ImportedPartLine::MAX_DESCRIPTION, mb_strlen($line->description()));
        self::assertTrue($line->needsHumanReview());
        // El texto original se conserva completo (sólo normalizado en espacios).
        self::assertSame(trim($long), $line->sourceText());
        self::assertSame($long, $line->sourceText() . ' ');
        self::assertStringContainsString('Texto original del documento:', (string) $line->notes());
    }

    public function testSourceTextIdenticalToTheDescriptionIsNotRepeatedInTheNotes(): void
    {
        $line = $this->line(['description' => 'Filtro de aceite', 'quantity' => 4, 'source_text' => 'Filtro de aceite']);

        self::assertNull($line->sourceText());
        self::assertNull($line->notes());
    }

    public function testNotesKeepTheUnitAndTheOriginalTextBecauseTheTableHasNoColumnsForThem(): void
    {
        $line = $this->line([
            'description' => 'Filtro de aceite',
            'quantity' => 4,
            'unit' => 'u',
            'source_text' => 'FILTRO ACEITE 4 U',
        ]);

        $notes = (string) $line->notes();
        self::assertStringContainsString('Unidad declarada en el documento: u.', $notes);
        self::assertStringContainsString('Texto original del documento: FILTRO ACEITE 4 U', $notes);
    }

    public function testRowsWithoutUsableDescriptionAreNotPersisted(): void
    {
        $lines = ImportedPartLine::listFromDetected([
            ['description' => '   ', 'quantity' => 2],
            ['description' => 'Filtro de aceite', 'quantity' => 2],
            'no es una fila',
        ]);

        self::assertCount(1, $lines);
        self::assertSame('Filtro de aceite', $lines[0]->description());
    }

    /** @param array<string,mixed> $detected */
    private function line(array $detected): ImportedPartLine
    {
        $line = ImportedPartLine::fromDetected($detected);
        self::assertNotNull($line);

        return $line;
    }
}
