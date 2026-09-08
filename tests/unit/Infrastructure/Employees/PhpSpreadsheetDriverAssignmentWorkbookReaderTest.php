<?php

declare(strict_types=1);

use App\Infrastructure\Employees\PhpSpreadsheetDriverAssignmentWorkbookReader;
use PHPUnit\Framework\TestCase;

final class PhpSpreadsheetDriverAssignmentWorkbookReaderTest extends TestCase
{
    /** @dataProvider plateProvider */
    public function testNormalizesPlateFromRealWorkbookVariants(string $raw, string $expected): void
    {
        self::assertSame($expected, PhpSpreadsheetDriverAssignmentWorkbookReader::normalizePlate($raw));
    }

    public static function plateProvider(): iterable
    {
        yield 'argentine with space' => ['OJG 526', 'OJG526'];
        yield 'leading whitespace' => [' JLH877', 'JLH877'];
        yield 'nbsp' => ["BEN4G47\u{00A0}", 'BEN4G47'];
        yield 'mercosur separator' => ['AA 123 BB', 'AA123BB'];
    }

    public function testDashMeansNoDriverInsteadOfEmployeeNamedDash(): void
    {
        self::assertNull(PhpSpreadsheetDriverAssignmentWorkbookReader::normalizeDriver('-'));
        self::assertNull(PhpSpreadsheetDriverAssignmentWorkbookReader::normalizeDriver(' SIN CHOFER '));
        self::assertSame('RAMOS CRISTIAN', PhpSpreadsheetDriverAssignmentWorkbookReader::normalizeDriver('  RAMOS   CRISTIAN '));
    }
}
