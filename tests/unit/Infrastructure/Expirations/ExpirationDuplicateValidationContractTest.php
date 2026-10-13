<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExpirationDuplicateValidationContractTest extends TestCase
{
    public function testCreateAndUpdateOnlyCollideWithActiveExpirationVersions(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/Expirations.php');
        self::assertIsString($source);

        self::assertGreaterThanOrEqual(2, substr_count($source, "->where('activo', 1)"));
        self::assertStringContainsString("->where('tipo_vencimiento_id',", $source);
        self::assertStringContainsString("->where('fecha_vencimiento',", $source);
        self::assertStringContainsString("->where('id !=',", $source);
    }

    public function testEquipmentUiMakesExpirationDateCorrectionExplicit(): void
    {
        $source = file_get_contents(ROOTPATH . 'frontend/src/pages/operations/EquipmentDetailPage.vue');
        self::assertIsString($source);

        self::assertStringContainsString('Editar vencimiento', $source);
        self::assertStringContainsString('<Teleport to="body">', $source);
        self::assertStringContainsString('Fecha de vencimiento (DD/MM/AAAA)', $source);
    }

    public function testManualDateParserUsesDayMonthYearAndRejectsImpossibleDates(): void
    {
        $controller = (new ReflectionClass(App\Controllers\Expirations::class))->newInstanceWithoutConstructor();
        $parser = new ReflectionMethod(App\Controllers\Expirations::class, 'parseDate');
        $parser->setAccessible(true);

        $october = $parser->invoke($controller, '12/10/2027');
        $december = $parser->invoke($controller, '10/12/2027');

        self::assertInstanceOf(DateTimeImmutable::class, $october);
        self::assertInstanceOf(DateTimeImmutable::class, $december);
        self::assertSame('2027-10-12', $october->format('Y-m-d'));
        self::assertSame('2027-12-10', $december->format('Y-m-d'));
        self::assertNull($parser->invoke($controller, '31/02/2027'));
        self::assertNull($parser->invoke($controller, '12/10/27'));
    }
}
