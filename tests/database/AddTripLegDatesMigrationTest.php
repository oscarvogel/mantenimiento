<?php

declare(strict_types=1);

use App\Database\Migrations\AddTripLegDates;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class AddTripLegDatesMigrationTest extends TestCase
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
            flete_liquidacion_ars REAL NULL,
            observaciones TEXT NULL
        )');
        require_once APPPATH . 'Database/Migrations/2026-11-12-090000_AddTripLegDates.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpAddsNullableDateColumnsAndKeepsExistingRows(): void
    {
        $this->database->table('viaje_operativo_tramos')->insert(['observaciones' => 'Tramo sin fechas']);

        $migration = new AddTripLegDates(Database::forge($this->database));
        $migration->up();

        self::assertTrue($this->database->fieldExists('fecha_inicio', 'viaje_operativo_tramos'));
        self::assertTrue($this->database->fieldExists('fecha_fin', 'viaje_operativo_tramos'));

        $row = $this->database->table('viaje_operativo_tramos')->get()->getRowArray();
        self::assertNull($row['fecha_inicio']);
        self::assertNull($row['fecha_fin']);
        self::assertSame('Tramo sin fechas', $row['observaciones']);
    }

    public function testUpIsIdempotent(): void
    {
        $migration = new AddTripLegDates(Database::forge($this->database));
        $migration->up();
        $migration->up();

        self::assertTrue($this->database->fieldExists('fecha_inicio', 'viaje_operativo_tramos'));
        self::assertTrue($this->database->fieldExists('fecha_fin', 'viaje_operativo_tramos'));
    }

    public function testDownRemovesTheColumnsWhenNoLegHasDates(): void
    {
        $migration = new AddTripLegDates(Database::forge($this->database));
        $migration->up();
        $migration->down();

        self::assertFalse($this->database->fieldExists('fecha_inicio', 'viaje_operativo_tramos'));
        self::assertFalse($this->database->fieldExists('fecha_fin', 'viaje_operativo_tramos'));
    }

    public function testDownRefusesToRunWhileThereAreLegsWithDates(): void
    {
        $migration = new AddTripLegDates(Database::forge($this->database));
        $migration->up();
        $this->database->table('viaje_operativo_tramos')->insert(['fecha_inicio' => '2026-11-02']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fechas cargadas');

        $migration->down();
    }
}
