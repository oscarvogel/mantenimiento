<?php

declare(strict_types=1);

use App\Database\Migrations\AddTripLegLiquidationCurrency;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class AddTripLegLiquidationCurrencyMigrationTest extends TestCase
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
            moneda TEXT,
            cotizacion REAL,
            observaciones TEXT NULL
        )');
        require_once APPPATH . 'Database/Migrations/2026-11-12-150000_AddTripLegLiquidationCurrency.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpAddsTheLiquidationCurrencyAndBackfillsTheExistingLegs(): void
    {
        $this->database->table('viaje_operativo_tramos')->insert(['moneda' => 'USD', 'cotizacion' => 1250]);

        $migration = new AddTripLegLiquidationCurrency(Database::forge($this->database));
        $migration->up();

        self::assertTrue($this->database->fieldExists('moneda_liquidacion', 'viaje_operativo_tramos'));
        self::assertTrue($this->database->fieldExists('cotizacion_liquidacion', 'viaje_operativo_tramos'));

        $row = $this->database->table('viaje_operativo_tramos')->get()->getRowArray();
        self::assertSame('USD', $row['moneda_liquidacion']);
        self::assertEquals(1250.0, (float) $row['cotizacion_liquidacion']);
    }

    public function testUpIsIdempotent(): void
    {
        $migration = new AddTripLegLiquidationCurrency(Database::forge($this->database));
        $migration->up();
        $migration->up();

        self::assertTrue($this->database->fieldExists('moneda_liquidacion', 'viaje_operativo_tramos'));
        self::assertTrue($this->database->fieldExists('cotizacion_liquidacion', 'viaje_operativo_tramos'));
    }

    public function testDownRemovesTheColumnsWhenTheLiquidationUsesTheInvoiceCurrency(): void
    {
        $migration = new AddTripLegLiquidationCurrency(Database::forge($this->database));
        $migration->up();
        $migration->down();

        self::assertFalse($this->database->fieldExists('moneda_liquidacion', 'viaje_operativo_tramos'));
        self::assertFalse($this->database->fieldExists('cotizacion_liquidacion', 'viaje_operativo_tramos'));
    }

    public function testDownRefusesToRunWhileThereAreLegsWithAnotherLiquidationCurrency(): void
    {
        $migration = new AddTripLegLiquidationCurrency(Database::forge($this->database));
        $migration->up();
        $this->database->table('viaje_operativo_tramos')->insert([
            'moneda' => 'USD',
            'cotizacion' => 1250,
            'moneda_liquidacion' => 'ARS',
            'cotizacion_liquidacion' => 1,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('moneda de liquidacion');

        $migration->down();
    }
}
