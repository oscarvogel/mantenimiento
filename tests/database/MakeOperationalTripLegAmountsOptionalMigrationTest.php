<?php

declare(strict_types=1);

use App\Database\Migrations\MakeOperationalTripLegAmountsOptional;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class MakeOperationalTripLegAmountsOptionalMigrationTest extends TestCase
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
        $this->database->query('CREATE TABLE viaje_operativo_tramos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            flete_facturacion REAL NOT NULL DEFAULT 0,
            flete_facturacion_ars REAL NOT NULL DEFAULT 0,
            flete_liquidacion REAL NOT NULL DEFAULT 0,
            flete_liquidacion_ars REAL NOT NULL DEFAULT 0
        )');
        require_once APPPATH . 'Database/Migrations/2026-11-11-090000_MakeOperationalTripLegAmountsOptional.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpAllowsNullAmountsAndDownReversesWhenThereAreNoNulls(): void
    {
        $migration = new MakeOperationalTripLegAmountsOptional(Database::forge($this->database));
        $migration->up();

        $this->database->table('viaje_operativo_tramos')->insert([
            'flete_facturacion' => null,
            'flete_facturacion_ars' => null,
            'flete_liquidacion' => null,
            'flete_liquidacion_ars' => null,
        ]);
        self::assertSame(1, $this->database->table('viaje_operativo_tramos')->countAllResults());

        $this->database->table('viaje_operativo_tramos')->where('id >', 0)->delete();
        $migration->down();

        $this->database->table('viaje_operativo_tramos')->insert(['flete_facturacion' => 0]);
        self::assertSame(1, $this->database->table('viaje_operativo_tramos')->countAllResults());
    }

    public function testDownRefusesToRunWhileThereAreNullAmounts(): void
    {
        $migration = new MakeOperationalTripLegAmountsOptional(Database::forge($this->database));
        $migration->up();
        $this->database->table('viaje_operativo_tramos')->insert([
            'flete_facturacion' => null,
            'flete_facturacion_ars' => null,
            'flete_liquidacion' => null,
            'flete_liquidacion_ars' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sin importe');

        $migration->down();
    }
}
