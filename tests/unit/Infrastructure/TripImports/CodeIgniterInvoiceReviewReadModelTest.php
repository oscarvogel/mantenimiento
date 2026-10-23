<?php

declare(strict_types=1);

use App\Application\TripImports\InvoiceTripMatcher;
use App\Infrastructure\TripImports\CodeIgniterInvoiceReviewReadModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterInvoiceReviewReadModelTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La revisión de facturas requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE viajes (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, origen TEXT, cuit_origen TEXT, dominio_origen TEXT, numero_viaje INTEGER, fecha_salida TEXT, fecha_regreso TEXT)');
        $this->database->query('CREATE TABLE viaje_tramos (id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, descripcion_origen TEXT, descripcion_destino TEXT, importe REAL, fecha_salida TEXT)');
        $this->database->query('CREATE TABLE viajes_facturas (id INTEGER PRIMARY KEY, empresa_id INTEGER, importacion_id INTEGER, clave_legacy INTEGER, fecha TEXT, desc_tipo TEXT, total REAL, total_ars REAL, moneda TEXT, codigo_cliente_origen INTEGER)');
        $this->database->query('CREATE TABLE viaje_factura_lineas (id INTEGER PRIMARY KEY, factura_id INTEGER, empresa_id INTEGER, item INTEGER, nombre TEXT, unidad_origen TEXT, origen_texto TEXT, destino_texto TEXT, remitos_texto TEXT)');
        $this->database->query('CREATE TABLE viaje_factura_viaje (id INTEGER PRIMARY KEY, factura_id INTEGER, viaje_id INTEGER, empresa_id INTEGER, estado TEXT, confianza TEXT, puntaje INTEGER)');

        $this->database->table('viajes')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'sucursal_id' => 10, 'origen' => 'LEGACY_TRANSPORTE', 'cuit_origen' => '23-28059786-4', 'dominio_origen' => 'AC 532 DD', 'numero_viaje' => 10, 'fecha_salida' => '2026-10-20', 'fecha_regreso' => '2026-10-22'],
            ['id' => 2, 'empresa_id' => 5, 'sucursal_id' => 10, 'origen' => 'LEGACY_TRANSPORTE', 'cuit_origen' => '23-28059786-4', 'dominio_origen' => 'AC 532 DD', 'numero_viaje' => 20, 'fecha_salida' => '2026-10-23', 'fecha_regreso' => '2026-10-25'],
        ]);
        $this->database->table('viaje_tramos')->insertBatch([
            ['id' => 1, 'viaje_id' => 1, 'empresa_id' => 5, 'descripcion_origen' => 'ROSARIO', 'descripcion_destino' => 'CORDOBA', 'importe' => 100000, 'fecha_salida' => '2026-10-20'],
            ['id' => 2, 'viaje_id' => 2, 'empresa_id' => 5, 'descripcion_origen' => 'ROSARIO', 'descripcion_destino' => 'CORDOBA', 'importe' => 200000, 'fecha_salida' => '2026-10-23'],
        ]);
        $this->database->table('viajes_facturas')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'importacion_id' => 7, 'clave_legacy' => 900, 'fecha' => '2026-10-21', 'desc_tipo' => 'FAC-A', 'total' => 100000, 'total_ars' => 100000, 'moneda' => 'PES', 'codigo_cliente_origen' => 10],
            ['id' => 2, 'empresa_id' => 5, 'importacion_id' => 7, 'clave_legacy' => 901, 'fecha' => '2026-10-24', 'desc_tipo' => 'FAC-A', 'total' => 200000, 'total_ars' => 200000, 'moneda' => 'PES', 'codigo_cliente_origen' => 10],
        ]);
        $this->database->table('viaje_factura_lineas')->insertBatch([
            ['id' => 1, 'factura_id' => 1, 'empresa_id' => 5, 'item' => 1, 'nombre' => 'UN FLETE', 'unidad_origen' => 'AC532DD', 'origen_texto' => 'ROSARIO', 'destino_texto' => 'CORDOBA', 'remitos_texto' => null],
            ['id' => 2, 'factura_id' => 2, 'empresa_id' => 5, 'item' => 1, 'nombre' => 'UN FLETE', 'unidad_origen' => 'AC532DD', 'origen_texto' => 'ROSARIO', 'destino_texto' => 'CORDOBA', 'remitos_texto' => null],
        ]);
        $this->database->table('viaje_factura_viaje')->insert([
            'id' => 1, 'factura_id' => 2, 'viaje_id' => 2, 'empresa_id' => 5, 'estado' => 'CONFIRMADO', 'confianza' => 'MANUAL', 'puntaje' => null,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testReviewShowsSuggestionsAndCurrentLinks(): void
    {
        $readModel = new CodeIgniterInvoiceReviewReadModel($this->database, new InvoiceTripMatcher());
        $result = $readModel->review(5, 7, null, 1, 20);

        self::assertSame(2, $result['total']);
        $byClave = [];
        foreach ($result['items'] as $item) {
            $byClave[$item['clave_legacy']] = $item;
        }

        self::assertSame('AC532DD', $byClave[900]['unit']);
        self::assertSame(1, $byClave[900]['candidates'][0]['trip_id']);
        self::assertSame('ALTA', $byClave[900]['candidates'][0]['confidence']);
        self::assertSame('CONFIRMADO', $byClave[901]['link']['estado']);
        self::assertSame(2, $byClave[901]['link']['trip_id']);
    }

    public function testReviewFiltersWithoutLink(): void
    {
        $readModel = new CodeIgniterInvoiceReviewReadModel($this->database, new InvoiceTripMatcher());
        $result = $readModel->review(5, 7, 'SIN_VINCULO', 1, 20);

        self::assertSame(1, $result['total']);
        self::assertSame(900, $result['items'][0]['clave_legacy']);
    }

    public function testTripsSearch(): void
    {
        $readModel = new CodeIgniterInvoiceReviewReadModel($this->database, new InvoiceTripMatcher());
        $trips = $readModel->trips(5, 'AC 532');

        self::assertCount(2, $trips);
        $numbers = array_column($trips, 'number');
        sort($numbers);
        self::assertSame([10, 20], $numbers);
    }
}
