<?php

declare(strict_types=1);

use App\Infrastructure\Assets\CodeIgniterEquipmentFleetLookup;
use App\Infrastructure\Trips\CodeIgniterTripFormReadModel;
use App\Infrastructure\Trips\CodeIgniterTripLocalityLookup;
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
        $this->database->query('CREATE TABLE empresas (id INTEGER PRIMARY KEY, razon_social TEXT, nombre_fantasia TEXT NULL, cuit TEXT NULL)');
        $this->database->query('CREATE TABLE viajes_operativos_numeracion (empresa_id INTEGER PRIMARY KEY, ultimo_numero INTEGER, updated_at TEXT NULL)');
        $this->database->query('CREATE TABLE lecturas_equipo (id INTEGER PRIMARY KEY, empresa_id INTEGER, equipo_id INTEGER, fecha_lectura TEXT, kilometraje INTEGER NULL, anulada INTEGER)');
        $this->database->query('CREATE TABLE viajes_porcentajes_liquidacion_chofer (id INTEGER PRIMARY KEY, empresa_id INTEGER, empleado_id INTEGER, porcentaje REAL, vigente_desde TEXT, vigente_hasta TEXT NULL, activo INTEGER)');
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo TEXT, nombre TEXT, estado INTEGER, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE tipos_equipo (id INTEGER PRIMARY KEY, nombre TEXT)');
        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, tipo_equipo_id INTEGER, codigo TEXT, patente TEXT, estado TEXT, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE equipo_relaciones (id INTEGER PRIMARY KEY, empresa_id INTEGER, equipo_principal_id INTEGER, equipo_relacionado_id INTEGER, tipo_relacion TEXT, desde TEXT, hasta TEXT NULL)');
        $this->database->query('CREATE TABLE empleados (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, apellido TEXT, legajo TEXT NULL, activo INTEGER, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE employee_equipment_assignments (id INTEGER PRIMARY KEY, empresa_id INTEGER, empleado_id INTEGER, equipo_id INTEGER, rol TEXT, fecha_desde TEXT, fecha_hasta TEXT NULL)');
        $this->database->query('CREATE TABLE clientes (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo_origen TEXT, nombre TEXT, identificacion_fiscal TEXT NULL, activo INTEGER, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE viajes_domicilios_clientes (id INTEGER PRIMARY KEY, empresa_id INTEGER, cliente_id INTEGER, alias TEXT, calle TEXT, localidad TEXT, activo INTEGER)');
        $this->database->query('CREATE TABLE viajes_unidades_carga (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, simbolo TEXT, activo INTEGER)');
        $this->database->query('CREATE TABLE viajes_monedas (codigo TEXT PRIMARY KEY, nombre TEXT, activo INTEGER)');
        $this->database->query('CREATE TABLE paises (id INTEGER PRIMARY KEY, nombre TEXT, activo INTEGER)');
        $this->database->query('CREATE TABLE provincias (id INTEGER PRIMARY KEY, pais_id INTEGER, nombre TEXT, activo INTEGER)');
        $this->database->query('CREATE TABLE localidades (id INTEGER PRIMARY KEY, provincia_id INTEGER, nombre TEXT, contexto_administrativo TEXT NULL, activo INTEGER)');

        $this->database->table('empresas')->insert(['id' => 5, 'razon_social' => 'Transporte Mariela SRL', 'nombre_fantasia' => 'Transporte Mariela', 'cuit' => '23-28059786-4']);
        $this->database->table('viajes_operativos_numeracion')->insert(['empresa_id' => 5, 'ultimo_numero' => 114]);
        $this->database->table('sucursales')->insert(['id' => 10, 'empresa_id' => 5, 'codigo' => 'AR', 'nombre' => 'Argentina', 'estado' => 1, 'deleted_at' => null]);
        $this->database->table('tipos_equipo')->insertBatch([
            ['id' => 1, 'nombre' => 'Camión'],
            ['id' => 2, 'nombre' => 'Acoplado'],
        ]);
        $this->database->table('equipos')->insertBatch([
            ['id' => 7, 'empresa_id' => 5, 'sucursal_id' => 10, 'tipo_equipo_id' => 1, 'codigo' => 'T01', 'patente' => 'AA111AA', 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 9, 'empresa_id' => 5, 'sucursal_id' => 10, 'tipo_equipo_id' => 2, 'codigo' => 'A01', 'patente' => 'AC222AC', 'estado' => 'ACTIVO', 'deleted_at' => null],
        ]);
        $this->database->table('equipo_relaciones')->insert([
            'id' => 1, 'empresa_id' => 5, 'equipo_principal_id' => 7, 'equipo_relacionado_id' => 9,
            'tipo_relacion' => 'TRACTOR_ACOPLADO', 'desde' => '2026-01-01 00:00:00', 'hasta' => null,
        ]);
        $this->database->table('empleados')->insert(['id' => 11, 'empresa_id' => 5, 'nombre' => 'Juan', 'apellido' => 'Pérez', 'legajo' => '100', 'activo' => 1, 'deleted_at' => null]);
        $this->database->table('employee_equipment_assignments')->insert([
            'id' => 1, 'empresa_id' => 5, 'empleado_id' => 11, 'equipo_id' => 7, 'rol' => 'CHOFER',
            'fecha_desde' => '2026-01-01', 'fecha_hasta' => null,
        ]);
        $this->database->table('viajes_porcentajes_liquidacion_chofer')->insert([
            'id' => 1, 'empresa_id' => 5, 'empleado_id' => 11, 'porcentaje' => 14,
            'vigente_desde' => '2026-01-01', 'vigente_hasta' => null, 'activo' => 1,
        ]);
        $this->database->table('lecturas_equipo')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'equipo_id' => 7, 'fecha_lectura' => '2026-10-18 08:00:00', 'kilometraje' => 145200, 'anulada' => 0],
            ['id' => 2, 'empresa_id' => 5, 'equipo_id' => 7, 'fecha_lectura' => '2026-10-19 08:00:00', 'kilometraje' => 145300, 'anulada' => 0],
        ]);
        $this->database->table('clientes')->insert(['id' => 3, 'empresa_id' => 5, 'codigo_origen' => '324', 'nombre' => 'Cliente Sur', 'identificacion_fiscal' => '30-1-2', 'activo' => 1, 'deleted_at' => null]);
        $this->database->table('viajes_domicilios_clientes')->insert(['id' => 4, 'empresa_id' => 5, 'cliente_id' => 3, 'alias' => 'Planta', 'calle' => 'Ruta 9', 'localidad' => 'Rosario', 'activo' => 1]);
        $this->database->table('viajes_unidades_carga')->insert(['id' => 2, 'empresa_id' => 5, 'nombre' => 'Tonelada', 'simbolo' => 'tn', 'activo' => 1]);
        $this->database->table('viajes_monedas')->insert(['codigo' => 'ARS', 'nombre' => 'Peso argentino', 'activo' => 1]);
        $this->database->table('paises')->insert(['id' => 1, 'nombre' => 'Argentina', 'activo' => 1]);
        $this->database->table('provincias')->insert(['id' => 1, 'pais_id' => 1, 'nombre' => 'Santa Fe', 'activo' => 1]);
        $this->database->table('localidades')->insert(['id' => 1, 'provincia_id' => 1, 'nombre' => 'Rosario', 'contexto_administrativo' => 'Departamento Rosario', 'activo' => 1]);
        self::assertSame('Departamento Rosario', $this->database->table('localidades')->where('id', 1)->get()->getRowArray()['contexto_administrativo']);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testOptionsExposeTheDataNeededByTheCreateForm(): void
    {
        $options = $this->readModel()->options(5, [10]);

        self::assertSame('Transporte Mariela', $options['company']['name']);
        self::assertSame('23-28059786-4', $options['company']['taxId']);
        self::assertSame(115, $options['nextNumber']);
        self::assertCount(1, $options['branches']);
        self::assertSame('AA111AA · T01', $options['tractors'][0]['label']);
        self::assertSame('AA111AA', $options['tractors'][0]['plate']);
        self::assertSame(145_300, $options['tractors'][0]['lastKm']);
        self::assertSame('AC222AC · A01', $options['trailers'][0]['label']);
        self::assertSame('Pérez, Juan', $options['drivers'][0]['label']);
        self::assertSame(7, $options['drivers'][0]['equipmentId']);
        self::assertSame('AA111AA', $options['drivers'][0]['assignedEquipmentLabel']);
        self::assertSame(14.0, $options['drivers'][0]['liquidationPercentage']);
        self::assertSame('Cliente Sur', $options['customers'][0]['label']);
        self::assertSame('324', $options['customers'][0]['code']);
        self::assertSame('Planta · Ruta 9 · Rosario', $options['addresses'][0]['label']);
        self::assertSame('Tonelada (tn)', $options['loadUnits'][0]['label']);
    }

    public function testExponeElSemirremolqueEnganchadoAlTractor(): void
    {
        $options = $this->readModel()->options(5, [10]);

        self::assertSame([9], $options['tractors'][0]['linkedTrailerIds']);
        self::assertSame([], $options['trailers'][0]['linkedTrailerIds'] ?? []);
    }

    public function testBuscaLocalidadesConContextoProvinciaYPais(): void
    {
        $localities = (new CodeIgniterTripLocalityLookup($this->database))->search('Ros');
        self::assertSame(1, $localities[0]['id']);
        self::assertSame('Rosario', $localities[0]['name']);
        self::assertSame('Departamento Rosario', $localities[0]['context']);
        self::assertSame('Rosario · Departamento Rosario · Santa Fe · Argentina', $localities[0]['label']);
        self::assertCount(1, (new CodeIgniterTripLocalityLookup($this->database))->search('Departamento Rosario'));
    }

    public function testSinSemirremolqueEnganchadoLaListaQuedaVacia(): void
    {
        $this->database->table('equipo_relaciones')->where('empresa_id', 5)->delete();

        $options = $this->readModel()->options(5, [10]);

        self::assertSame([], $options['tractors'][0]['linkedTrailerIds']);
    }

    private function readModel(): CodeIgniterTripFormReadModel
    {
        return new CodeIgniterTripFormReadModel(
            $this->database,
            new CodeIgniterEquipmentFleetLookup($this->database),
        );
    }
}
