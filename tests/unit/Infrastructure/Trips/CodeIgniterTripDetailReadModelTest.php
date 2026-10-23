<?php

declare(strict_types=1);

use App\Infrastructure\Trips\CodeIgniterTripDetailReadModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterTripDetailReadModelTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La lectura de detalle requiere sqlite3.');
        }
        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE viajes (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, origen TEXT, dominio_origen TEXT, numero_viaje INTEGER, fecha_salida TEXT, fecha_regreso TEXT, legajo_chofer_origen TEXT, empleado_id INTEGER, equipo_id INTEGER, observaciones_1 TEXT, observaciones_2 TEXT, observaciones_3 TEXT, km_salida INTEGER, km_regreso INTEGER, contabilizado INTEGER, fecha_contabilizacion TEXT, porcentaje_chofer TEXT, activo INTEGER)');
        $this->database->query('CREATE TABLE viaje_tramos (id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, item INTEGER, codigo_origen_destino INTEGER, cantidad_kilometros TEXT, toneladas TEXT, precio TEXT, importe TEXT, importe_inicial TEXT, porcentaje_facturacion TEXT, descripcion_origen TEXT, descripcion_destino TEXT, observaciones_1 TEXT, observaciones_2 TEXT, observaciones_3 TEXT, observaciones_4 TEXT, remito TEXT, codigo_alternativo TEXT, cobrado INTEGER, codigo_cliente_origen TEXT, cliente_id INTEGER, fecha_salida TEXT, fecha_llegada TEXT)');
        $this->database->query('CREATE TABLE viaje_combustible (id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, item INTEGER, codigo_concepto TEXT, litros TEXT, importe TEXT, observaciones TEXT, fecha TEXT, kilometraje_actual INTEGER)');
        $this->database->query('CREATE TABLE viaje_gastos (id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, item INTEGER, codigo_concepto TEXT, importe TEXT, observaciones TEXT, fecha TEXT)');
        $this->database->query('CREATE TABLE viaje_cuenta_corriente (id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, item INTEGER, codigo_concepto TEXT, debitos TEXT, creditos TEXT, debitos_moneda TEXT, creditos_moneda TEXT, importe_moneda TEXT, cotizacion TEXT, id_moneda INTEGER, observaciones TEXT, fecha TEXT)');
        $this->database->query('CREATE TABLE viaje_cuenta_corriente_tramos (id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, item INTEGER, sub_item INTEGER, tipo_comprobante TEXT, punto_venta TEXT, numero_comprobante TEXT, importe TEXT, fecha TEXT)');
        $this->database->query('CREATE TABLE viaje_otros_haberes (id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, item INTEGER, codigo_concepto TEXT, importe TEXT, observaciones TEXT, fecha TEXT)');
        $this->database->table('viajes')->insertBatch([
            ['id' => 10, 'empresa_id' => 7, 'sucursal_id' => 3, 'origen' => 'LEGACY_TRANSPORTE', 'dominio_origen' => 'ABC123', 'numero_viaje' => 42, 'fecha_salida' => '2026-09-01'],
            ['id' => 11, 'empresa_id' => 7, 'sucursal_id' => 9, 'origen' => 'LEGACY_TRANSPORTE', 'dominio_origen' => 'DEF456', 'numero_viaje' => 43, 'fecha_salida' => '2026-09-02'],
            ['id' => 12, 'empresa_id' => 8, 'sucursal_id' => 3, 'origen' => 'LEGACY_TRANSPORTE', 'dominio_origen' => 'GHI789', 'numero_viaje' => 44, 'fecha_salida' => '2026-09-03'],
        ]);
        $this->database->table('viaje_tramos')->insertBatch([
            ['id' => 1, 'viaje_id' => 10, 'empresa_id' => 7, 'sucursal_id' => 3, 'item' => 1, 'descripcion_origen' => 'Posadas', 'descripcion_destino' => 'Iguazu', 'importe' => '100.00'],
            ['id' => 2, 'viaje_id' => 10, 'empresa_id' => 8, 'sucursal_id' => 3, 'item' => 2, 'descripcion_origen' => 'AJENO', 'descripcion_destino' => 'AJENO', 'importe' => '999.00'],
        ]);
        $this->database->table('viaje_combustible')->insert(['id' => 3, 'viaje_id' => 10, 'empresa_id' => 7, 'sucursal_id' => 3, 'item' => 1, 'codigo_concepto' => 'DIESEL', 'litros' => '75.00', 'importe' => '90.00', 'fecha' => '2026-09-02', 'kilometraje_actual' => 1200]);
        $this->database->table('viaje_gastos')->insert(['id' => 4, 'viaje_id' => 10, 'empresa_id' => 7, 'sucursal_id' => 3, 'item' => 1, 'codigo_concepto' => 'PEAJE', 'importe' => '10.00', 'observaciones' => 'Ruta nacional', 'fecha' => '2026-09-02']);
        $this->database->table('viaje_cuenta_corriente')->insert(['id' => 5, 'viaje_id' => 10, 'empresa_id' => 7, 'sucursal_id' => 3, 'item' => 1, 'codigo_concepto' => 'FLETE', 'debitos' => '100.00', 'creditos' => '0.00', 'importe_moneda' => '100.00', 'cotizacion' => '1.000000', 'id_moneda' => 1, 'fecha' => '2026-09-03']);
        $this->database->table('viaje_cuenta_corriente_tramos')->insert(['id' => 6, 'viaje_id' => 10, 'empresa_id' => 7, 'sucursal_id' => 3, 'item' => 1, 'sub_item' => 1, 'tipo_comprobante' => 'FACTURA', 'punto_venta' => '0001', 'numero_comprobante' => '00000042', 'importe' => '100.00', 'fecha' => '2026-09-03']);
        $this->database->table('viaje_otros_haberes')->insert(['id' => 7, 'viaje_id' => 10, 'empresa_id' => 7, 'sucursal_id' => 3, 'item' => 1, 'codigo_concepto' => 'ADICIONAL', 'importe' => '5.00', 'observaciones' => 'Adicional informado', 'fecha' => '2026-09-03']);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testReturnsTripDetailAndOnlyRelationsInTheSameCompanyAndBranch(): void
    {
        $detail = (new CodeIgniterTripDetailReadModel($this->database))->find(7, [3], 10);

        self::assertSame(10, $detail['trip']['id']);
        self::assertSame('ABC123', $detail['trip']['dominio_origen']);
        self::assertCount(1, $detail['segments']);
        self::assertSame('Posadas', $detail['segments'][0]['descripcion_origen']);
        self::assertSame('Iguazu', $detail['segments'][0]['descripcion_destino']);
        self::assertSame('100.00', $detail['segments'][0]['importe']);
        self::assertSame('75.00', $detail['fuel'][0]['litros']);
        self::assertSame('PEAJE', $detail['expenses'][0]['codigo_concepto']);
        self::assertSame('FLETE', $detail['accounts'][0]['codigo_concepto']);
        self::assertSame('00000042', $detail['accountSegments'][0]['numero_comprobante']);
        self::assertSame('ADICIONAL', $detail['otherEarnings'][0]['codigo_concepto']);
    }

    public function testDoesNotReturnTripFromAnotherCompanyOrBranch(): void
    {
        $readModel = new CodeIgniterTripDetailReadModel($this->database);

        self::assertNull($readModel->find(8, [3], 10));
        self::assertNull($readModel->find(7, [3], 11));
        self::assertNull($readModel->find(7, [], 10));
    }
}
