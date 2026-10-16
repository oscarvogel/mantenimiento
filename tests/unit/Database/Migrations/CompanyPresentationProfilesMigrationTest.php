<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CompanyPresentationProfilesMigrationTest extends TestCase
{
    private function migrationSource(): string
    {
        $source = file_get_contents(APPPATH . 'Database/Migrations/2026-10-16-090000_CreateCompanyPresentationProfiles.php');
        self::assertIsString($source);

        return $source;
    }

    public function testMigrationCreatesCompanyConfigurationAndAppendOnlyHistory(): void
    {
        $source = $this->migrationSource();

        self::assertStringContainsString("createTable('empresa_configuracion_presentacion'", $source);
        self::assertStringContainsString("createTable('empresa_presentacion_perfil_historial'", $source);
        self::assertStringContainsString("'empresa_id'", $source);
        self::assertStringContainsString("'frontend_profile'", $source);
        self::assertStringContainsString("'perfil_exclusivo'", $source);
        self::assertStringContainsString("'perfil_anterior'", $source);
        self::assertStringContainsString("'perfil_nuevo'", $source);
        self::assertStringContainsString("'actor_user_id'", $source);
        self::assertStringContainsString("addForeignKey('empresa_id', 'empresas', 'id'", $source);
        self::assertStringContainsString("addForeignKey('actor_user_id', 'usuarios', 'id'", $source);
    }

    public function testMigrationEnforcesOneConfigurationPerCompanyAndExclusiveCustomProfiles(): void
    {
        $source = $this->migrationSource();

        self::assertStringContainsString("addUniqueKey('empresa_id'", $source);
        self::assertStringContainsString("addUniqueKey('perfil_exclusivo'", $source);
        self::assertStringContainsString("'perfil_exclusivo' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true]", $source);
    }

    public function testDownRefusesToDeletePersistedConfigurationOrAudit(): void
    {
        $source = $this->migrationSource();

        self::assertStringContainsString('countAllResults()', $source);
        self::assertStringContainsString('RuntimeException', $source);
        self::assertStringContainsString('down() no puede eliminar', $source);
        self::assertStringContainsString("dropTable('empresa_presentacion_perfil_historial'", $source);
        self::assertStringContainsString("dropTable('empresa_configuracion_presentacion'", $source);
    }
}
