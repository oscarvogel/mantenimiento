<?php

declare(strict_types=1);

use App\Domain\Importations\ImportType;
use PHPUnit\Framework\TestCase;

final class ImportTypeTest extends TestCase
{
    public function testTsaImportTypesExposeCanonicalContracts(): void
    {
        self::assertSame(ImportType::UNIDADES_TRANSPORTE, ImportType::parse(' unidades_transporte '));
        self::assertSame(ImportType::VENCIMIENTOS, ImportType::parse('vencimientos'));
        self::assertContains('sucursal_codigo', ImportType::UNIDADES_TRANSPORTE->requiredHeaders());
        self::assertSame(
            ['equipo_codigo', 'tipo_vencimiento', 'fecha_vencimiento'],
            ImportType::VENCIMIENTOS->requiredHeaders(),
        );
    }
}
