<?php

declare(strict_types=1);

use App\Database\Migrations\EnableOperationalTripCostMaintenance;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class EnableOperationalTripCostMaintenanceMigrationTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de migración requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        foreach (['usuarios', 'empresas', 'sucursales', 'viajes_operativos'] as $table) {
            $this->database->query('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY)');
        }

        $this->database->query('CREATE TABLE viaje_operativo_combustible (id INTEGER PRIMARY KEY, updated_at DATETIME NULL)');
        $this->database->query('CREATE TABLE viaje_operativo_gastos (id INTEGER PRIMARY KEY)');
        $this->database->query('CREATE TABLE viaje_operativo_caja_movimientos (id INTEGER PRIMARY KEY)');

        require_once APPPATH . 'Database/Migrations/2026-11-01-100000_EnableOperationalTripCostMaintenance.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpContinuesFromColumnsAlreadyPresentAndCanBeRetried(): void
    {
        $migration = new EnableOperationalTripCostMaintenance(Database::forge($this->database));

        $migration->up();
        $migration->up();

        foreach (['viaje_operativo_combustible', 'viaje_operativo_gastos', 'viaje_operativo_caja_movimientos'] as $table) {
            foreach (['updated_at', 'updated_by', 'deleted_at', 'deleted_by'] as $column) {
                self::assertContains($column, $this->database->getFieldNames($table), $table . ' no tiene ' . $column);
            }
        }
        self::assertTrue($this->database->tableExists('viaje_operativo_movimientos_auditoria'));
    }
}
