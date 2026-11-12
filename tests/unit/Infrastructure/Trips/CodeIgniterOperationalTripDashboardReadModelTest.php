<?php

declare(strict_types=1);

use App\Infrastructure\Trips\CodeIgniterOperationalTripDashboardReadModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterOperationalTripDashboardReadModelTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión del tablero de viajes requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo TEXT, nombre TEXT, estado INTEGER, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo TEXT, patente TEXT)');
        $this->database->query('CREATE TABLE empleados (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, apellido TEXT)');
        $this->database->query('CREATE TABLE viajes_operativos (
            id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, numero INTEGER, estado TEXT,
            fecha_salida_planificada TEXT, fecha_regreso_planificada TEXT, fecha_salida_real TEXT NULL,
            fecha_regreso_real TEXT NULL, km_salida INTEGER NULL, km_regreso INTEGER NULL
        )');
        $this->database->query('CREATE TABLE viaje_operativo_combustible (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, litros REAL, importe_ars REAL
        )');
        $this->database->query('CREATE TABLE viaje_operativo_gastos (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, importe_ars REAL
        )');
        $this->database->query('CREATE TABLE viaje_operativo_tramos (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, orden INTEGER, origen TEXT, destino TEXT, flete_facturacion_ars REAL
        )');
        $this->database->query('CREATE TABLE viaje_operativo_asignaciones (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, rol TEXT,
            equipo_id INTEGER NULL, empleado_id INTEGER NULL
        )');
        $this->database->query('CREATE TABLE viaje_operativo_paradas (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, orden INTEGER, ubicacion TEXT
        )');

        $this->database->table('sucursales')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'codigo' => 'AR', 'nombre' => 'Argentina', 'estado' => 1, 'deleted_at' => null],
            ['id' => 20, 'empresa_id' => 5, 'codigo' => 'BR', 'nombre' => 'Brasil', 'estado' => 1, 'deleted_at' => null],
        ]);
        $this->database->table('equipos')->insertBatch([
            ['id' => 7, 'empresa_id' => 5, 'codigo' => 'T01', 'patente' => 'AA111AA'],
            ['id' => 8, 'empresa_id' => 5, 'codigo' => 'T02', 'patente' => 'BB222BB'],
        ]);
        $this->database->table('empleados')->insert([
            'id' => 11, 'empresa_id' => 5, 'nombre' => 'Juan', 'apellido' => 'Pérez',
        ]);
        $this->database->table('viajes_operativos')->insertBatch([
            [
                'id' => 1, 'empresa_id' => 5, 'sucursal_id' => 10, 'numero' => 11, 'estado' => 'EN_CURSO',
                'fecha_salida_planificada' => '2026-10-01 08:00:00', 'fecha_regreso_planificada' => '2026-10-03 18:00:00',
                'fecha_salida_real' => '2026-10-01 08:00:00', 'fecha_regreso_real' => null,
                'km_salida' => 1000, 'km_regreso' => null,
            ],
            [
                'id' => 2, 'empresa_id' => 5, 'sucursal_id' => 10, 'numero' => 12, 'estado' => 'FINALIZADO',
                'fecha_salida_planificada' => '2026-10-10 08:00:00', 'fecha_regreso_planificada' => '2026-10-11 18:00:00',
                'fecha_salida_real' => '2026-10-10 08:00:00', 'fecha_regreso_real' => '2026-10-11 18:00:00',
                'km_salida' => 1000, 'km_regreso' => 1600,
            ],
            [
                'id' => 3, 'empresa_id' => 5, 'sucursal_id' => 20, 'numero' => 13, 'estado' => 'FINALIZADO',
                'fecha_salida_planificada' => '2026-10-12 08:00:00', 'fecha_regreso_planificada' => '2026-10-13 18:00:00',
                'fecha_salida_real' => '2026-10-12 08:00:00', 'fecha_regreso_real' => '2026-10-13 18:00:00',
                'km_salida' => 500, 'km_regreso' => 900,
            ],
            [
                'id' => 4, 'empresa_id' => 5, 'sucursal_id' => 10, 'numero' => 14, 'estado' => 'CANCELADO',
                'fecha_salida_planificada' => '2026-10-15 08:00:00', 'fecha_regreso_planificada' => '2026-10-16 18:00:00',
                'fecha_salida_real' => null, 'fecha_regreso_real' => null,
                'km_salida' => null, 'km_regreso' => null,
            ],
        ]);
        $this->database->table('viaje_operativo_combustible')->insertBatch([
            ['id' => 1, 'viaje_id' => 1, 'empresa_id' => 5, 'litros' => 100, 'importe_ars' => 100000],
            ['id' => 2, 'viaje_id' => 2, 'empresa_id' => 5, 'litros' => 300, 'importe_ars' => 300000],
            ['id' => 3, 'viaje_id' => 3, 'empresa_id' => 5, 'litros' => 50, 'importe_ars' => 50000],
        ]);
        $this->database->table('viaje_operativo_gastos')->insertBatch([
            ['id' => 1, 'viaje_id' => 1, 'empresa_id' => 5, 'importe_ars' => 50000],
            ['id' => 2, 'viaje_id' => 2, 'empresa_id' => 5, 'importe_ars' => 100000],
            ['id' => 3, 'viaje_id' => 3, 'empresa_id' => 5, 'importe_ars' => 20000],
        ]);
        $this->database->table('viaje_operativo_tramos')->insertBatch([
            ['id' => 1, 'viaje_id' => 1, 'empresa_id' => 5, 'orden' => 1, 'origen' => 'Rosario', 'destino' => 'Córdoba', 'flete_facturacion_ars' => 500000],
            ['id' => 2, 'viaje_id' => 2, 'empresa_id' => 5, 'orden' => 1, 'origen' => 'Córdoba', 'destino' => 'Mendoza', 'flete_facturacion_ars' => 900000],
            ['id' => 3, 'viaje_id' => 3, 'empresa_id' => 5, 'orden' => 1, 'origen' => 'Rosario', 'destino' => 'Córdoba', 'flete_facturacion_ars' => 300000],
            ['id' => 4, 'viaje_id' => 4, 'empresa_id' => 5, 'orden' => 1, 'origen' => 'Santa Fe', 'destino' => 'Rosario', 'flete_facturacion_ars' => 0],
            ['id' => 5, 'viaje_id' => 4, 'empresa_id' => 5, 'orden' => 2, 'origen' => 'Rosario', 'destino' => 'Bahía Blanca', 'flete_facturacion_ars' => 0],
        ]);
        $this->database->table('viaje_operativo_asignaciones')->insertBatch([
            ['id' => 1, 'viaje_id' => 1, 'empresa_id' => 5, 'rol' => 'TRACTOR', 'equipo_id' => 7, 'empleado_id' => null],
            ['id' => 2, 'viaje_id' => 1, 'empresa_id' => 5, 'rol' => 'CHOFER', 'equipo_id' => null, 'empleado_id' => 11],
            ['id' => 3, 'viaje_id' => 2, 'empresa_id' => 5, 'rol' => 'TRACTOR', 'equipo_id' => 8, 'empleado_id' => null],
        ]);
        $this->database->table('viaje_operativo_paradas')->insertBatch([
            ['id' => 1, 'viaje_id' => 1, 'empresa_id' => 5, 'orden' => 1, 'ubicacion' => 'Rosario'],
            ['id' => 2, 'viaje_id' => 1, 'empresa_id' => 5, 'orden' => 2, 'ubicacion' => 'Córdoba'],
            ['id' => 3, 'viaje_id' => 2, 'empresa_id' => 5, 'orden' => 1, 'ubicacion' => 'Córdoba'],
            ['id' => 4, 'viaje_id' => 2, 'empresa_id' => 5, 'orden' => 2, 'ubicacion' => 'Mendoza'],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testTripsReturnsAggregatedCostsAndRespectsScope(): void
    {
        $readModel = new CodeIgniterOperationalTripDashboardReadModel($this->database);

        $rows = $readModel->trips(5, [10], null);
        self::assertCount(3, $rows);
        $byId = array_column($rows, null, 'id');
        self::assertSame(100.0, $byId[1]['litros']);
        self::assertSame(100000.0, $byId[1]['combustible_ars']);
        self::assertSame(50000.0, $byId[1]['gastos_ars']);
        self::assertSame(500000.0, $byId[1]['flete_facturacion_ars']);
        self::assertSame(300.0, $byId[2]['litros']);
        self::assertNull($byId[4]['km_regreso']);

        self::assertCount(1, $readModel->trips(5, [20], null));
        self::assertCount(1, $readModel->trips(5, null, 20));
        self::assertSame([], $readModel->trips(5, [], null));
    }

    public function testDetailsReturnsEquipmentDriversAndRouteForCurrentTrips(): void
    {
        $details = (new CodeIgniterOperationalTripDashboardReadModel($this->database))->details(5, [1, 2]);

        self::assertSame('AA111AA · T01', $details[1]['tractor']);
        self::assertSame(['Juan Pérez'], $details[1]['choferes']);
        self::assertSame('Rosario', $details[1]['origen']);
        self::assertSame('Córdoba', $details[1]['destino']);
        self::assertSame(2, $details[1]['paradas']);
        self::assertSame(1, $details[1]['tramos']);
        self::assertSame('BB222BB · T02', $details[2]['tractor']);
        self::assertSame('Córdoba', $details[2]['origen']);
        self::assertSame('Mendoza', $details[2]['destino']);
        self::assertSame([], (new CodeIgniterOperationalTripDashboardReadModel($this->database))->details(5, []));
    }

    public function testTheRouteComesFromTheLegsEvenWithoutStops(): void
    {
        // El viaje 4 no tiene paradas registradas: el recorrido tiene que salir
        // de sus tramos, que son obligatorios.
        $details = (new CodeIgniterOperationalTripDashboardReadModel($this->database))->details(5, [4]);

        self::assertSame('Santa Fe', $details[4]['origen']);
        self::assertSame('Bahía Blanca', $details[4]['destino']);
        self::assertSame(0, $details[4]['paradas']);
        self::assertSame(2, $details[4]['tramos']);
    }

    public function testTheStopsCompleteTheRouteOnlyWhenThereAreNoLegs(): void
    {
        // Un viaje sin tramos conserva el recorrido de sus paradas.
        $this->database->table('viaje_operativo_tramos')->where('viaje_id', 2)->delete();
        $details = (new CodeIgniterOperationalTripDashboardReadModel($this->database))->details(5, [2]);

        self::assertSame('Córdoba', $details[2]['origen']);
        self::assertSame('Mendoza', $details[2]['destino']);
        self::assertSame(2, $details[2]['paradas']);
        self::assertSame(0, $details[2]['tramos']);
    }

    public function testBranchesRespectsCompanyAndBranchScope(): void
    {
        $readModel = new CodeIgniterOperationalTripDashboardReadModel($this->database);

        self::assertSame([
            ['id' => 10, 'code' => 'AR', 'name' => 'Argentina'],
        ], $readModel->branches(5, [10]));
        self::assertSame([], $readModel->branches(5, []));
        self::assertSame([], $readModel->branches(6, null));
    }
}
