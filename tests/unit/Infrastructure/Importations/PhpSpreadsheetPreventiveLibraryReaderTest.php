<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Importations;

use App\Infrastructure\Importations\PhpSpreadsheetPreventiveLibraryReader;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PhpSpreadsheetPreventiveLibraryReaderTest extends TestCase
{
    public function testRemovesWorksheetTablePartsBeforePhpSpreadsheetReadsTables(): void
    {
        $xml = '<worksheet><sheetData/><tableParts count="1"><tablePart r:id="rId1"/></tableParts></worksheet>';

        $cleaned = $this->invokePrivateString('removeTableParts', $xml);

        self::assertSame('<worksheet><sheetData/></worksheet>', $cleaned);
    }

    public function testRemovesOnlyTableRelationshipsFromWorksheetRelationships(): void
    {
        $xml = '<Relationships>'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="/xl/tables/table1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments" Target="../comments1.xml"/>'
            . '</Relationships>';

        $cleaned = $this->invokePrivateString('removeTableRelationships', $xml);

        self::assertStringNotContainsString('/xl/tables/table1.xml', $cleaned);
        self::assertStringContainsString('comments1.xml', $cleaned);
    }

    private function invokePrivateString(string $methodName, string $value): string
    {
        $method = new ReflectionMethod(PhpSpreadsheetPreventiveLibraryReader::class, $methodName);
        $method->setAccessible(true);

        return (string) $method->invoke(new PhpSpreadsheetPreventiveLibraryReader(), $value);
    }
}
