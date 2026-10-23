<?php

declare(strict_types=1);

use App\Application\Trips\TripProfitabilityFilters;
use App\Infrastructure\Trips\CodeIgniterTripProfitabilityReadModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterTripProfitabilityReadModelTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de rentabilidad requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo TEXT, nombre TEXT, estado INTEGER, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE viajes (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, origen TEXT, dominio_origen TEXT, numero_viaje INTEGER, fecha_salida TEXT, fecha_regreso TEXT, km_salida INTEGER, km_regreso INTEGER, porcentaje_chofer REAL)');
        $this->database->query('CREATE TABLE viaje_tramos (id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, importe REAL)');
        $this->database->query('CREATE TABLE viaje_combustible (id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, importe REAL)');
        $this->database->query('CREATE TABLE viaje_gastos (id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, importe REAL)');
        $this->database->query('CREATE TABLE viaje_otros_haberes (id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, importe REAL)');
        $this->database->query('CREATE TABLE viajes_facturas (id INTEGER PRIMARY KEY, empresa_id INTEGER, total_ars REAL, signo INTEGER)');
        $this->database->query('CREATE TABLE viaje_factura_viaje (id INTEGER PRIMARY KEY, factura_id INTEGER, viaje_id INTEGER, empresa_id INTEGER, estado TEXT)');

        $this->database->table('sucursales')->insert(['id' => 10, 'empresa_id' => 5, 'codigo' => 'AR', 'nombre' => 'Argentina', 'estado' => 1, 'deleted_at' => null]);
        $this->database->table('viajes')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'sucursal_id' => 10, 'origen' => 'LEGACY_TRANSPORTE', 'dominio_origen' => 'AC 532 DD', 'numero_viaje' => 10, 'fecha_salida' => '2026-10-20', 'fecha_regreso' => '2026-10-22', 'km_salida' => 100, 'km_regreso' => 200, 'porcentaje_chofer' => 14],
            ['id' => 2, 'empresa_id' => 5, 'sucursal_id' => 10, 'origen' => 'LEGACY_TRANSPORTE', 'dominio_origen' => 'AC 532 DD', 'numero_viaje' => 20, 'fecha_salida' => '2026-10-23', 'fecha_regreso' => '2026-10-25', 'km_salida' => 200, 'km_regreso' => 300, 'porcentaje_chofer' => 14],
            ['id' => 3, 'empresa_id' => 5, 'sucursal_id' => 10, 'origen' => 'LEGACY_TRANSPORTE', 'dominio_origen' => 'AC 532 DD', 'numero_viaje' => 30, 'fecha_salida' => '2026-10-26', 'fecha_regreso' => '2026-10-28', 'km_salida' => 300, 'km_regreso' => 400, 'porcentaje_chofer' => 14],
        ]);
        $this->database->table('viaje_tramos')->insertBatch([
            ['id' => 1, 'viaje_id' => 1, 'empresa_id' => 5, 'importe' => 500_000],
            ['id' => 2, 'viaje_id' => 3, 'empresa_id' => 5, 'importe' => 400_000],
        ]);
        $this->database->table('viaje_combustible')->insert(['id' => 1, 'viaje_id' => 1, 'empresa_id' => 5, 'importe' => 300_000]);
        $this->database->table('viaje_gastos')->insert(['id' => 1, 'viaje_id' => 1, 'empresa_id' => 5, 'importe' => 50_000]);
        $this->database->table('viaje_otros_haberes')->insert(['id' => 1, 'viaje_id' => 1, 'empresa_id' => 5, 'importe' => 100_000]);
        $this->database->table('viajes_facturas')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'total_ars' => 1_000_000, 'signo' => 1],
            ['id' => 2, 'empresa_id' => 5, 'total_ars' => 800_000, 'signo' => 1],
        ]);
        $this->database->table('viaje_factura_viaje')->insertBatch([
            ['id' => 1, 'factura_id' => 1, 'viaje_id' => 1, 'empresa_id' => 5, 'estado' => 'CONFIRMADO'],
            ['id' => 2, 'factura_id' => 2, 'viaje_id' => 3, 'empresa_id' => 5, 'estado' => 'AUTO'],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testRowsAggregateInvoiceRevenueAndDirectCosts(): void
    {
        $rows = (new CodeIgniterTripProfitabilityReadModel($this->database))
            ->rows(5, [10], new TripProfitabilityFilters());

        self::assertCount(3, $rows);
        $byNumber = [];
        foreach ($rows as $row) {
            $byNumber[$row['numero_viaje']] = $row;
        }
        $trip = $byNumber[10];
        self::assertSame(10, $trip['numero_viaje']);
        self::assertSame(1_000_000.0, $trip['revenue']);
        self::assertSame(1, $trip['invoice_count']);
        self::assertSame(300_000.0, $trip['fuel']);
        self::assertSame(50_000.0, $trip['expenses']);
        self::assertSame(100_000.0, $trip['other']);
        self::assertSame(500_000.0, $trip['flete']);

        self::assertSame(0.0, $byNumber[20]['revenue']);
        self::assertSame(0, $byNumber[20]['invoice_count']);
        self::assertSame(0.0, $byNumber[30]['fuel']);
        self::assertSame(400_000.0, $byNumber[30]['flete']);
    }

    public function testRowsRespectBranchAndDomainFilters(): void
    {
        $readModel = new CodeIgniterTripProfitabilityReadModel($this->database);

        self::assertSame([], $readModel->rows(5, [20], new TripProfitabilityFilters()));
        self::assertCount(3, $readModel->rows(5, [10], new TripProfitabilityFilters(domain: 'AC 532 DD')));
        self::assertCount(0, $readModel->rows(5, [10], new TripProfitabilityFilters(domain: 'OTRO')));
    }
}
