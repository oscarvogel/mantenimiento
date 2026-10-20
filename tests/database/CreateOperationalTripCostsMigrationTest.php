<?php

declare(strict_types=1);

use App\Database\Migrations\CreateOperationalTripCosts;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CreateOperationalTripCostsMigrationTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de costos requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        foreach (['viajes_operativos', 'empresas', 'sucursales', 'usuarios', 'viajes_categorias_gasto', 'viajes_medios_pago'] as $table) {
            $this->database->query('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY)');
        }

        require_once APPPATH . 'Database/Migrations/2026-10-20-100000_CreateOperationalTripCosts.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpCreatesCostTablesAndDownReversesThem(): void
    {
        $migration = new CreateOperationalTripCosts(Database::forge($this->database));
        $migration->up();

        foreach (['viaje_operativo_combustible', 'viaje_operativo_gastos', 'viaje_operativo_caja_movimientos'] as $table) {
            self::assertTrue($this->database->tableExists($table), 'Falta la tabla ' . $table);
        }

        $migration->down();

        foreach (['viaje_operativo_combustible', 'viaje_operativo_gastos', 'viaje_operativo_caja_movimientos'] as $table) {
            self::assertFalse($this->database->tableExists($table), 'No se eliminó la tabla ' . $table);
        }
    }
}
