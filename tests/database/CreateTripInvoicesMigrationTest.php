<?php

declare(strict_types=1);

use App\Database\Migrations\CreateTripInvoices;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CreateTripInvoicesMigrationTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de la migración de facturas requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        foreach (['empresas', 'importaciones', 'viajes', 'sucursales', 'usuarios'] as $table) {
            $this->database->query('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY)');
        }

        require_once APPPATH . 'Database/Migrations/2026-10-19-160000_CreateTripInvoices.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpCreatesInvoiceTablesAndDownReversesThem(): void
    {
        $migration = new CreateTripInvoices(Database::forge($this->database));
        $migration->up();

        foreach (['viajes_facturas', 'viaje_factura_lineas', 'viaje_factura_viaje'] as $table) {
            self::assertTrue($this->database->tableExists($table), 'Falta la tabla ' . $table);
        }

        $migration->down();

        foreach (['viajes_facturas', 'viaje_factura_lineas', 'viaje_factura_viaje'] as $table) {
            self::assertFalse($this->database->tableExists($table), 'No se eliminó la tabla ' . $table);
        }
    }
}
