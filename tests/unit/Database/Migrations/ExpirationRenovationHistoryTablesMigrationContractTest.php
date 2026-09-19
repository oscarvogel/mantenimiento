<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExpirationRenovationHistoryTablesMigrationContractTest extends TestCase
{
    public function testMigrationDeclaresTenantScopedTablesAndStrictForeignKeys(): void
    {
        $migration = file_get_contents(APPPATH . 'Database/Migrations/2026-09-17-110000_CreateExpirationRenovationHistoryTables.php');
        self::assertIsString($migration);

        self::assertStringContainsString("'vencimiento_evidencias'", $migration);
        self::assertStringContainsString("'vencimiento_renovaciones'", $migration);
        self::assertStringContainsString("'empresa_id'", $migration);
        self::assertStringContainsString("'vencimiento_id'", $migration);
        self::assertStringContainsString("'fecha_anterior'", $migration);
        self::assertStringContainsString("'fecha_nueva'", $migration);
        self::assertStringContainsString("'fecha_renovacion'", $migration);
        self::assertStringContainsString("'usuario_id'", $migration);
        self::assertStringContainsString("'evidencia_id'", $migration);
        self::assertStringContainsString("addForeignKey('empresa_id', 'empresas', 'id', 'RESTRICT', 'RESTRICT'", $migration);
        self::assertStringContainsString("addForeignKey(\n            'vencimiento_id',\n            'vencimientos',\n            'id'", $migration);
        self::assertStringContainsString("addForeignKey(\n            'evidencia_id',\n            'vencimiento_evidencias',\n            'id'", $migration);
        self::assertStringContainsString("'RESTRICT', 'RESTRICT'", $migration);
        self::assertStringContainsString("'SET NULL'", $migration);
        self::assertStringContainsString("createTable('vencimiento_renovaciones', true)", $migration);
        self::assertStringContainsString("createTable('vencimiento_evidencias', true)", $migration);
    }
}
