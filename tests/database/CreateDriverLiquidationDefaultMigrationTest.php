<?php

declare(strict_types=1);

use App\Database\Migrations\CreateDriverLiquidationDefault;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CreateDriverLiquidationDefaultMigrationTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión del porcentaje por defecto requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE empresas (id INTEGER PRIMARY KEY)');
        $this->database->query('CREATE TABLE usuarios (id INTEGER PRIMARY KEY)');
        $this->database->table('empresas')->insert(['id' => 4]);
        $this->database->table('usuarios')->insert(['id' => 7]);

        require_once APPPATH . 'Database/Migrations/2026-11-24-160000_CreateDriverLiquidationDefault.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpCreatesTheCompanyDefaultTable(): void
    {
        $migration = new CreateDriverLiquidationDefault(Database::forge($this->database));
        $migration->up();

        self::assertTrue($this->database->tableExists('viajes_porcentaje_liquidacion_defecto'));
        $fields = $this->database->getFieldNames('viajes_porcentaje_liquidacion_defecto');
        self::assertContains('empresa_id', $fields);
        self::assertContains('porcentaje', $fields);
        self::assertContains('created_by', $fields);
        self::assertContains('updated_by', $fields);
    }

    public function testTheTableStartsEmptySoNoCompanyGetsAPercentageWithoutAsking(): void
    {
        $migration = new CreateDriverLiquidationDefault(Database::forge($this->database));
        $migration->up();

        self::assertSame(0, $this->database->table('viajes_porcentaje_liquidacion_defecto')->countAllResults());
    }

    public function testUpIsIdempotent(): void
    {
        $migration = new CreateDriverLiquidationDefault(Database::forge($this->database));
        $migration->up();

        $second = new CreateDriverLiquidationDefault(Database::forge($this->database));
        $second->up();

        self::assertTrue($this->database->tableExists('viajes_porcentaje_liquidacion_defecto'));
    }

    public function testDownRemovesTheTable(): void
    {
        $migration = new CreateDriverLiquidationDefault(Database::forge($this->database));
        $migration->up();

        $migration->down();

        self::assertFalse($this->database->tableExists('viajes_porcentaje_liquidacion_defecto'));
    }
}
