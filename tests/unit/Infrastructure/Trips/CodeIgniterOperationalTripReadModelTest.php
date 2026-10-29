<?php

declare(strict_types=1);

use App\Application\Trips\OperationalTripFilters;
use App\Infrastructure\Trips\CodeIgniterOperationalTripReadModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterOperationalTripReadModelTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión del listado de viajes requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE empresas (id INTEGER PRIMARY KEY, razon_social TEXT, nombre_fantasia TEXT)');
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo TEXT, nombre TEXT, estado INTEGER, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE tipos_equipo (id INTEGER PRIMARY KEY, nombre TEXT)');
        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, tipo_equipo_id INTEGER, codigo TEXT, patente TEXT, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE empleados (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, apellido TEXT, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE clientes (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE viajes_domicilios_clientes (id INTEGER PRIMARY KEY, empresa_id INTEGER, cliente_id INTEGER, alias TEXT, calle TEXT, localidad TEXT)');
        $this->database->query('CREATE TABLE viajes_unidades_carga (id INTEGER PRIMARY KEY, nombre TEXT, simbolo TEXT)');
        $this->database->query('CREATE TABLE viajes_operativos (
            id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, numero INTEGER, estado TEXT,
            fecha_salida_planificada TEXT, fecha_regreso_planificada TEXT, fecha_salida_real TEXT NULL,
            fecha_regreso_real TEXT NULL, km_salida INTEGER NULL, km_regreso INTEGER NULL,
            observaciones TEXT NULL, motivo_cancelacion TEXT NULL, created_at TEXT NULL
        )');
        $this->database->query('CREATE TABLE viaje_operativo_asignaciones (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, rol TEXT,
            equipo_id INTEGER NULL, empleado_id INTEGER NULL, desde TEXT, hasta TEXT NULL, motivo TEXT NULL,
            porcentaje_liquidacion REAL NULL
        )');
        $this->database->query('CREATE TABLE viaje_operativo_paradas (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, orden INTEGER,
            tipo TEXT, cliente_id INTEGER NULL, domicilio_id INTEGER NULL, ubicacion TEXT,
            fecha_estimada TEXT NULL, fecha_real TEXT NULL, descripcion_carga TEXT NULL, cantidad REAL NULL,
            unidad_carga_id INTEGER NULL, observaciones TEXT NULL
        )');
        $this->database->query('CREATE TABLE viaje_operativo_combustible (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, tramo_id INTEGER NULL, empresa_id INTEGER, sucursal_id INTEGER, fecha TEXT,
            litros REAL, importe REAL, moneda TEXT, cotizacion REAL, importe_ars REAL, kilometraje INTEGER NULL,
            estacion TEXT NULL, comprobante TEXT NULL, observaciones TEXT NULL
        )');
        $this->database->query('CREATE TABLE viaje_operativo_gastos (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, tramo_id INTEGER NULL, empresa_id INTEGER, sucursal_id INTEGER, categoria_id INTEGER NULL,
            fecha TEXT, importe REAL, moneda TEXT, cotizacion REAL, importe_ars REAL, medio_pago_id INTEGER NULL,
            pagado_de_caja INTEGER, pagado_por TEXT NULL, comprobante TEXT NULL, observaciones TEXT NULL
        )');
        $this->database->query('CREATE TABLE viaje_operativo_otros_haberes (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, empleado_id INTEGER,
            fecha TEXT, concepto TEXT, importe REAL, moneda TEXT, cotizacion REAL, importe_ars REAL, observaciones TEXT NULL
        )');
        $this->database->query('CREATE TABLE viaje_operativo_tramos (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, orden INTEGER,
            cliente_id INTEGER NULL, origen TEXT, destino TEXT, km REAL NULL, moneda TEXT, cotizacion REAL,
            flete_facturacion REAL, flete_facturacion_ars REAL, flete_liquidacion REAL, flete_liquidacion_ars REAL,
            observaciones TEXT NULL
        )');
        $this->database->query('CREATE TABLE viaje_operativo_caja_movimientos (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, tipo TEXT,
            fecha TEXT, importe REAL, moneda TEXT, cotizacion REAL, importe_ars REAL, observaciones TEXT NULL
        )');
        $this->database->query('CREATE TABLE viajes_categorias_gasto (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, activo INTEGER)');
        $this->database->query('CREATE TABLE viajes_medios_pago (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, activo INTEGER)');
        $this->database->query('CREATE TABLE viajes_monedas (codigo TEXT PRIMARY KEY, nombre TEXT, activo INTEGER)');

        $this->database->table('empresas')->insert(['id' => 5, 'razon_social' => 'Empresa de prueba S.A.', 'nombre_fantasia' => 'Empresa de prueba']);
        $this->database->table('sucursales')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'codigo' => 'AR', 'nombre' => 'Argentina', 'estado' => 1, 'deleted_at' => null],
            ['id' => 20, 'empresa_id' => 5, 'codigo' => 'BR', 'nombre' => 'Brasil', 'estado' => 1, 'deleted_at' => null],
        ]);
        $this->database->table('tipos_equipo')->insertBatch([
            ['id' => 1, 'nombre' => 'Camión'],
            ['id' => 2, 'nombre' => 'Acoplado'],
        ]);
        $this->database->table('equipos')->insertBatch([
            ['id' => 7, 'empresa_id' => 5, 'sucursal_id' => 10, 'tipo_equipo_id' => 1, 'codigo' => 'T01', 'patente' => 'AA111AA', 'deleted_at' => null],
            ['id' => 9, 'empresa_id' => 5, 'sucursal_id' => 10, 'tipo_equipo_id' => 2, 'codigo' => 'A01', 'patente' => 'AC222AC', 'deleted_at' => null],
        ]);
        $this->database->table('empleados')->insert(['id' => 11, 'empresa_id' => 5, 'nombre' => 'Juan', 'apellido' => 'Pérez', 'deleted_at' => null]);
        $this->database->table('clientes')->insert(['id' => 3, 'empresa_id' => 5, 'nombre' => 'Cliente Sur', 'deleted_at' => null]);
        $this->database->table('viajes_domicilios_clientes')->insert(['id' => 4, 'empresa_id' => 5, 'cliente_id' => 3, 'alias' => 'Planta', 'calle' => 'Ruta 9', 'localidad' => 'Rosario']);
        $this->database->table('viajes_unidades_carga')->insert(['id' => 2, 'nombre' => 'Tonelada', 'simbolo' => 'tn']);
        $this->database->table('viajes_operativos')->insert([
            'id' => 1, 'empresa_id' => 5, 'sucursal_id' => 10, 'numero' => 42, 'estado' => 'PLANIFICADO',
            'fecha_salida_planificada' => '2026-10-20 08:00:00', 'fecha_regreso_planificada' => '2026-10-21 18:00:00',
            'fecha_salida_real' => null, 'fecha_regreso_real' => null, 'km_salida' => null, 'km_regreso' => null,
            'observaciones' => 'Carga programada', 'motivo_cancelacion' => null, 'created_at' => '2026-10-19 10:00:00',
        ]);
        $this->database->table('viaje_operativo_asignaciones')->insertBatch([
            ['id' => 1, 'viaje_id' => 1, 'empresa_id' => 5, 'sucursal_id' => 10, 'rol' => 'TRACTOR', 'equipo_id' => 7, 'empleado_id' => null, 'desde' => '2026-10-20 08:00:00', 'hasta' => '2026-10-21 18:00:00', 'motivo' => null, 'porcentaje_liquidacion' => null],
            ['id' => 2, 'viaje_id' => 1, 'empresa_id' => 5, 'sucursal_id' => 10, 'rol' => 'ACOPLADO', 'equipo_id' => 9, 'empleado_id' => null, 'desde' => '2026-10-20 08:00:00', 'hasta' => '2026-10-21 18:00:00', 'motivo' => null, 'porcentaje_liquidacion' => null],
            ['id' => 3, 'viaje_id' => 1, 'empresa_id' => 5, 'sucursal_id' => 10, 'rol' => 'CHOFER', 'equipo_id' => null, 'empleado_id' => 11, 'desde' => '2026-10-20 08:00:00', 'hasta' => '2026-10-21 18:00:00', 'motivo' => null, 'porcentaje_liquidacion' => 14.0],
        ]);
        $this->database->table('viaje_operativo_tramos')->insert([
            'id' => 1, 'viaje_id' => 1, 'empresa_id' => 5, 'sucursal_id' => 10, 'orden' => 1,
            'cliente_id' => 3, 'origen' => 'Rosario', 'destino' => 'Córdoba', 'km' => 320,
            'moneda' => 'ARS', 'cotizacion' => 1, 'flete_facturacion' => 1_000_000,
            'flete_facturacion_ars' => 1_000_000, 'flete_liquidacion' => 800_000,
            'flete_liquidacion_ars' => 800_000, 'observaciones' => null,
        ]);
        $this->database->table('viaje_operativo_gastos')->insert([
            'id' => 1, 'viaje_id' => 1, 'tramo_id' => 1, 'empresa_id' => 5, 'sucursal_id' => 10,
            'categoria_id' => null, 'fecha' => '2026-10-20 12:00:00', 'importe' => 50_000,
            'moneda' => 'ARS', 'cotizacion' => 1, 'importe_ars' => 50_000, 'medio_pago_id' => null,
            'pagado_de_caja' => 0, 'pagado_por' => 'CHOFER', 'comprobante' => null, 'observaciones' => null,
        ]);
        $this->database->table('viaje_operativo_otros_haberes')->insert([
            'id' => 1, 'viaje_id' => 1, 'empresa_id' => 5, 'sucursal_id' => 10, 'empleado_id' => 11,
            'fecha' => '2026-10-20', 'concepto' => 'Espera', 'importe' => 25_000, 'moneda' => 'ARS',
            'cotizacion' => 1, 'importe_ars' => 25_000, 'observaciones' => null,
        ]);
        $this->database->table('viaje_operativo_paradas')->insertBatch([
            ['id' => 1, 'viaje_id' => 1, 'empresa_id' => 5, 'sucursal_id' => 10, 'orden' => 1, 'tipo' => 'CARGA', 'cliente_id' => 3, 'domicilio_id' => 4, 'ubicacion' => 'Rosario', 'fecha_estimada' => '2026-10-20 08:30:00', 'fecha_real' => null, 'descripcion_carga' => 'Soja', 'cantidad' => 30, 'unidad_carga_id' => 2, 'observaciones' => null],
            ['id' => 2, 'viaje_id' => 1, 'empresa_id' => 5, 'sucursal_id' => 10, 'orden' => 2, 'tipo' => 'DESCARGA', 'cliente_id' => 3, 'domicilio_id' => null, 'ubicacion' => 'Córdoba', 'fecha_estimada' => null, 'fecha_real' => null, 'descripcion_carga' => null, 'cantidad' => null, 'unidad_carga_id' => null, 'observaciones' => null],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testListShowsRouteEquipmentDriversAndStatus(): void
    {
        $payload = (new CodeIgniterOperationalTripReadModel($this->database))
            ->list(5, [10], new OperationalTripFilters(), 1, 20);

        self::assertSame(1, $payload['total']);
        $trip = $payload['items'][0];
        self::assertSame(42, $trip['numero']);
        self::assertSame('Planificado', $trip['estado_label']);
        self::assertSame('AA111AA · T01', $trip['tractor']);
        self::assertSame('AC222AC · A01', $trip['acoplado']);
        self::assertSame(['Juan Pérez'], $trip['choferes']);
        self::assertSame('Rosario', $trip['origen']);
        self::assertSame('Córdoba', $trip['destino']);
        self::assertSame(2, $trip['paradas']);
    }

    public function testListRespectsBranchScopeAndSearch(): void
    {
        $readModel = new CodeIgniterOperationalTripReadModel($this->database);

        self::assertSame(0, $readModel->list(5, [20], new OperationalTripFilters(), 1, 20)['total']);
        self::assertSame(1, $readModel->list(5, null, new OperationalTripFilters(search: '42'), 1, 20)['total']);
        self::assertSame(0, $readModel->list(5, null, new OperationalTripFilters(status: 'EN_CURSO'), 1, 20)['total']);
    }

    public function testFindReturnsAssignmentsAndStopsWithLabels(): void
    {
        $detail = (new CodeIgniterOperationalTripReadModel($this->database))->find(5, [10], 1);

        self::assertNotNull($detail);
        self::assertSame('Empresa de prueba S.A.', $detail['company']['name']);
        self::assertSame(42, $detail['trip']['numero']);
        self::assertSame('Argentina', $detail['trip']['sucursal']);
        self::assertCount(3, $detail['assignments']);
        self::assertSame('Tractor / camión', $detail['assignments'][0]['rol_label']);
        self::assertSame('Juan Pérez', $detail['assignments'][2]['label']);
        self::assertSame(14.0, $detail['assignments'][2]['porcentaje_liquidacion']);
        self::assertCount(1, $detail['legs']);
        self::assertSame('Rosario', $detail['legs'][0]['origen']);
        self::assertSame(1_000_000.0, $detail['legs'][0]['flete_facturacion_ars']);
        self::assertSame('CHOFER', $detail['expenses'][0]['pagado_por']);
        self::assertCount(1, $detail['otherEarnings']);
        self::assertSame('Espera', $detail['otherEarnings'][0]['concepto']);
        self::assertSame(25_000.0, $detail['otherEarnings'][0]['importe_ars']);
        self::assertCount(2, $detail['stops']);
        self::assertSame('Cliente Sur', $detail['stops'][0]['cliente']);
        self::assertSame('Planta · Ruta 9 · Rosario', $detail['stops'][0]['domicilio']);
        self::assertSame('Tonelada tn', $detail['stops'][0]['unidad']);
    }

    public function testFindOutsideScopeReturnsNull(): void
    {
        $readModel = new CodeIgniterOperationalTripReadModel($this->database);

        self::assertNull($readModel->find(5, [20], 1));
        self::assertNull($readModel->find(6, null, 1));
    }
}
