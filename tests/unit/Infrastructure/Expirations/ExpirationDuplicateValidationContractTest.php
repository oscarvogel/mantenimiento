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

        self::assertStringContainsString('Editar vencimiento / corregir fecha', $source);
        self::assertStringContainsString('label="Fecha de vencimiento"', $source);
    }
}
