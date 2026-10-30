<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GeographicCatalogMigrationContractTest extends TestCase
{
    private function source(): string
    {
        $path = APPPATH . 'Database/Migrations/2026-10-30-130000_ExtendGeographicCatalogs.php';
        if (! is_file($path)) {
            return '';
        }
        $source = file_get_contents($path);
        self::assertIsString($source);

        return $source;
    }

    public function testCatalogUsesOneGlobalSourceIdentityAndStoresCoordinates(): void
    {
        $source = $this->source();

        self::assertSame(2, substr_count($source, "'identificador_fuente' =>"));
        self::assertStringContainsString("addUniqueKey('identificador_fuente'", $source);
        self::assertStringContainsString("'contexto_administrativo'", $source);
        self::assertStringContainsString("'categoria_fuente'", $source);
        self::assertStringContainsString("'latitud' => ['type' => 'DECIMAL', 'constraint' => '10,7'", $source);
        self::assertStringContainsString("'longitud' => ['type' => 'DECIMAL', 'constraint' => '10,7'", $source);
    }

    public function testImportHistoryRecordsSourceChecksumCountsAndGlobalActor(): void
    {
        $source = $this->source();

        self::assertStringContainsString("createTable('geografia_importaciones'", $source);
        self::assertStringContainsString("'sha256_archivo'", $source);
        self::assertStringContainsString("'localidades_nuevas'", $source);
        self::assertStringContainsString("'localidades_actualizadas'", $source);
        self::assertStringContainsString("'usuario_id'", $source);
        self::assertStringContainsString("addForeignKey('usuario_id', 'usuarios', 'id'", $source);
        self::assertStringContainsString("addForeignKey('pais_id', 'paises', 'id'", $source);
    }

    public function testDownPreservesImportedCatalogAndAuditHistory(): void
    {
        $source = $this->source();

        self::assertStringContainsString('countAllResults()', $source);
        self::assertStringContainsString('RuntimeException', $source);
        self::assertStringContainsString('no puede eliminar', $source);
    }
}
