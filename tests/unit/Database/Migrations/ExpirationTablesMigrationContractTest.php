<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExpirationTablesMigrationContractTest extends TestCase
{
    public function testMigrationScopesCatalogAndRecordsByCompanyAndSupportsBothSubjects(): void
    {
        $migration = file_get_contents(APPPATH . 'Database/Migrations/2026-09-08-181500_CreateExpirationTables.php');
        self::assertIsString($migration);

        self::assertStringContainsString("'empresa_id'", $migration);
        self::assertStringContainsString("'equipo_id'", $migration);
        self::assertStringContainsString("'empleado_id'", $migration);
        self::assertStringContainsString("'sujeto_tipo'", $migration);
        self::assertStringContainsString("'uq_expiration_type_company_name'", $migration);
        self::assertStringContainsString("'uq_expiration_equipment_type_date'", $migration);
        self::assertStringContainsString("'uq_expiration_employee_type_date'", $migration);
        self::assertStringContainsString("addForeignKey('equipo_id', 'equipos'", $migration);
        self::assertStringContainsString("addForeignKey('empleado_id', 'empleados'", $migration);
    }
}
