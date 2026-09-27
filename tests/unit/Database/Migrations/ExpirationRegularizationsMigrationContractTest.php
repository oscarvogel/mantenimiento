<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExpirationRegularizationsMigrationContractTest extends TestCase
{
    public function testMigrationKeepsRegularizationAndEvidenceScopedAndAuditable(): void
    {
        $migration = file_get_contents(APPPATH . 'Database/Migrations/2026-09-27-083500_CreateExpirationRegularizations.php');
        self::assertIsString($migration);

        self::assertStringContainsString("createTable('vencimiento_regularizaciones'", $migration);
        self::assertStringContainsString("createTable('vencimiento_regularizacion_adjuntos'", $migration);
        self::assertStringContainsString("'empresa_id'", $migration);
        self::assertStringContainsString("'vencimiento_id'", $migration);
        self::assertStringContainsString("'empleado_id'", $migration);
        self::assertStringContainsString("'nueva_fecha_vencimiento'", $migration);
        self::assertStringContainsString("'estado'", $migration);
        self::assertStringContainsString("'revisado_por'", $migration);
        self::assertStringContainsString("'motivo_rechazo'", $migration);
        self::assertStringContainsString("addForeignKey('vencimiento_id', 'vencimientos'", $migration);
        self::assertStringContainsString("addForeignKey('regularizacion_id', 'vencimiento_regularizaciones'", $migration);
    }
}
