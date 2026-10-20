<?php

declare(strict_types=1);

use App\Infrastructure\Trips\CodeIgniterTripFormReadModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterTripFormReadModelTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('El alta de viajes requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo TEXT, nombre TEXT, estado INTEGER, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE tipos_equipo (id INTEGER PRIMARY KEY, nombre TEXT)');
        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, tipo_equipo_id INTEGER, codigo TEXT, patente TEXT, estado TEXT, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE empleados (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, apellido TEXT, legajo TEXT NULL, activo INTEGER, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE clientes (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, identificacion_fiscal TEXT NULL, activo INTEGER, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE viajes_domicilios_clientes (id INTEGER PRIMARY KEY, empresa_id INTEGER, cliente_id INTEGER, alias TEXT, calle TEXT, localidad TEXT, activo INTEGER)');
        $this->database->query('CREATE TABLE viajes_unidades_carga (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, simbolo TEXT, activo INTEGER)');

        $this->database->table('sucursales')->insert(['id' => 10, 'empresa_id' => 5, 'codigo' => 'AR', 'nombre' => 'Argentina', 'estado' => 1, 'deleted_at' => null]);
        $this->database->table('tipos_equipo')->insertBatch([
            ['id' => 1, 'nombre' => 'Camión'],
            ['id' => 2, 'nombre' => 'Acoplado'],
        ]);
        $this->database->table('equipos')->insertBatch([
            ['id' => 7, 'empresa_id' => 5, 'sucursal_id' => 10, 'tipo_equipo_id' => 1, 'codigo' => 'T01', 'patente' => 'AA111AA', 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 9, 'empresa_id' => 5, 'sucursal_id' => 10, 'tipo_equipo_id' => 2, 'codigo' => 'A01', 'patente' => 'AC222AC', 'estado' => 'ACTIVO', 'deleted_at' => null],
        ]);
        $this->database->table('empleados')->insert(['id' => 11, 'empresa_id' => 5, 'nombre' => 'Juan', 'apellido' => 'Pérez', 'legajo' => '100', 'activo' => 1, 'deleted_at' => null]);
        $this->database->table('clientes')->insert(['id' => 3, 'empresa_id' => 5, 'nombre' => 'Cliente Sur', 'identificacion_fiscal' => '30-1-2', 'activo' => 1, 'deleted_at' => null]);
        $this->database->table('viajes_domicilios_clientes')->insert(['id' => 4, 'empresa_id' => 5, 'cliente_id' => 3, 'alias' => 'Planta', 'calle' => 'Ruta 9', 'localidad' => 'Rosario', 'activo' => 1]);
        $this->database->table('viajes_unidades_carga')->insert(['id' => 2, 'empresa_id' => 5, 'nombre' => 'Tonelada', 'simbolo' => 'tn', 'activo' => 1]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testOptionsExposeTheDataNeededByTheCreateForm(): void
    {
        $options = (new CodeIgniterTripFormReadModel($this->database))->options(5, [10]);

        self::assertCount(1, $options['branches']);
        self::assertSame('AA111AA · T01', $options['tractors'][0]['label']);
        self::assertSame('AC222AC · A01', $options['trailers'][0]['label']);
        self::assertSame('Pérez, Juan', $options['drivers'][0]['label']);
        self::assertSame('Cliente Sur', $options['customers'][0]['label']);
        self::assertSame('Planta · Ruta 9 · Rosario', $options['addresses'][0]['label']);
        self::assertSame('Tonelada (tn)', $options['loadUnits'][0]['label']);
    }
}
