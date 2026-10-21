<?php

declare(strict_types=1);

use App\Database\Migrations\CreateOperationalTripLegs;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CreateOperationalTripLegsMigrationTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de la migración requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        foreach (['empresas', 'sucursales', 'clientes', 'usuarios', 'viajes_operativos'] as $table) {
            $this->database->query('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY)');
        }
        $this->database->query('CREATE TABLE viaje_operativo_combustible (id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER)');
        $this->database->query('CREATE TABLE viaje_operativo_gastos (id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER)');
        require_once APPPATH . 'Database/Migrations/2026-10-21-120000_CreateOperationalTripLegs.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpCreatesLegsAndAddsOptionalTramoToCostsAndDownReversesIt(): void
    {
        $migration = new CreateOperationalTripLegs(Database::forge($this->database));
        $migration->up();

        self::assertTrue($this->database->tableExists('viaje_operativo_tramos'));
        self::assertContains('tramo_id', $this->database->getFieldNames('viaje_operativo_combustible'));
        self::assertContains('tramo_id', $this->database->getFieldNames('viaje_operativo_gastos'));

        $migration->down();

        self::assertFalse($this->database->tableExists('viaje_operativo_tramos'));
        self::assertNotContains('tramo_id', $this->database->getFieldNames('viaje_operativo_combustible'));
        self::assertNotContains('tramo_id', $this->database->getFieldNames('viaje_operativo_gastos'));
    }
}
