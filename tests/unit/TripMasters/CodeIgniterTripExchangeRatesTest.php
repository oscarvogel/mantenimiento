<?php

declare(strict_types=1);

namespace Tests\Unit\TripMasters;

use App\Infrastructure\TripMasters\CodeIgniterTripMasterRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterTripExchangeRatesTest extends TestCase
{
    private BaseConnection $database;
    private CodeIgniterTripMasterRepository $repository;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La cotización de Viajes requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE viajes_monedas (id INTEGER PRIMARY KEY, codigo TEXT, nombre TEXT, activo INTEGER)');
        $this->database->query('CREATE TABLE viajes_cotizaciones (
            id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER, moneda TEXT, cotizacion REAL,
            created_at TEXT NULL, updated_at TEXT NULL, created_by INTEGER NULL, updated_by INTEGER NULL,
            UNIQUE (empresa_id, moneda)
        )');
        $this->database->table('viajes_monedas')->insertBatch([
            ['id' => 1, 'codigo' => 'ARS', 'nombre' => 'Peso argentino', 'activo' => 1],
            ['id' => 2, 'codigo' => 'USD', 'nombre' => 'Dólar estadounidense', 'activo' => 1],
            ['id' => 3, 'codigo' => 'EUR', 'nombre' => 'Euro', 'activo' => 1],
        ]);
        $this->database->table('viajes_cotizaciones')->insert([
            'empresa_id' => 5, 'moneda' => 'USD', 'cotizacion' => 1000,
        ]);
        $this->repository = new CodeIgniterTripMasterRepository($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testListsSharedCurrenciesWithTheCompanyRate(): void
    {
        $items = array_column($this->repository->list('cotizaciones', 5), null, 'moneda');

        self::assertSame(1.0, $items['ARS']['cotizacion']);
        self::assertSame(1000.0, $items['USD']['cotizacion']);
        self::assertNull($items['EUR']['cotizacion']);
    }

    public function testSavesAndUpdatesRatesWithoutAffectingOtherCompanies(): void
    {
        $this->repository->saveExchangeRates(5, ['USD' => 1500.5, 'EUR' => 2000.0], 12);

        $company = array_column($this->repository->list('cotizaciones', 5), null, 'moneda');
        self::assertSame(1500.5, $company['USD']['cotizacion']);
        self::assertSame(2000.0, $company['EUR']['cotizacion']);

        $other = array_column($this->repository->list('cotizaciones', 6), null, 'moneda');
        self::assertNull($other['USD']['cotizacion']);
        self::assertNull($other['EUR']['cotizacion']);
    }

    public function testValidatesSharedCurrencyCodes(): void
    {
        self::assertTrue($this->repository->sharedCurrencyExists('ARS'));
        self::assertTrue($this->repository->sharedCurrencyExists('usd'));
        self::assertFalse($this->repository->sharedCurrencyExists('XXX'));
    }
}
