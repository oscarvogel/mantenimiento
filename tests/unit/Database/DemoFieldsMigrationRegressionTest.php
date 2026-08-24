<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

final class DemoFieldsMigrationRegressionTest extends TestCase
{
    public function testDuplicateDemoMigrationIsKeptAsNoOp(): void
    {
        $path = ROOTPATH . 'app/Database/Migrations/2026-08-20-193000_AddDemoFieldsToEmpresas.php';
        $source = file_get_contents($path);

        self::assertIsString($source);
        self::assertStringContainsString('final class AddDemoFieldsToEmpresasV2 extends Migration', $source);
        self::assertStringNotContainsString("addColumn('empresas'", $source);
        self::assertStringNotContainsString("dropColumn('empresas'", $source);
    }
}
