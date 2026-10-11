<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telematic;

use App\Infrastructure\Telematic\CodeIgniterFleetTelemetryBoardReader;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterFleetTelemetryBoardReaderTest extends TestCase
{
    private BaseConnection $db;
    private CodeIgniterFleetTelemetryBoardReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
        ]);
        $this->db->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, estado INTEGER, deleted_at TEXT NULL)');
        $this->db->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, codigo TEXT, patente TEXT, estado TEXT, deleted_at TEXT NULL)');
        $this->db->query('CREATE TABLE integraciones_telemetria (id INTEGER PRIMARY KEY, empresa_id INTEGER, proveedor TEXT, nombre TEXT, activo INTEGER)');
        $this->db->query('CREATE TABLE equipo_telemetria (id INTEGER PRIMARY KEY, empresa_id INTEGER, integracion_id INTEGER, equipo_id INTEGER, unidad_externa TEXT, rol TEXT, activo INTEGER)');
        $this->db->query('CREATE TABLE telematia_ultima_lectura (id INTEGER PRIMARY KEY, empresa_id INTEGER, integracion_id INTEGER, equipo_id INTEGER, unidad_externa TEXT, proveedor TEXT, observada_en TEXT, registrada_en TEXT, latitud TEXT NULL, longitud TEXT NULL, velocidad_kmh TEXT NULL, rumbo INTEGER NULL, altitud_m TEXT NULL, satelites INTEGER NULL, kilometraje INTEGER NULL, horas_decimales INTEGER NULL, motor_encendido INTEGER NULL, ralenti_activo INTEGER NULL, voltaje TEXT NULL, combustible_litros TEXT NULL, sensores_adicionales TEXT NULL, anomalias_sensor TEXT NULL, ausente INTEGER)');

        $this->db->table('sucursales')->insertBatch([
            ['id' => 41, 'empresa_id' => 8, 'nombre' => 'Rosario', 'estado' => 1, 'deleted_at' => null],
            ['id' => 42, 'empresa_id' => 8, 'nombre' => 'Córdoba', 'estado' => 1, 'deleted_at' => null],
            ['id' => 91, 'empresa_id' => 9, 'nombre' => 'Otra empresa', 'estado' => 1, 'deleted_at' => null],
        ]);
        $this->db->table('equipos')->insertBatch([
            ['id' => 101, 'empresa_id' => 8, 'sucursal_id' => 41, 'codigo' => 'AB123CD', 'patente' => 'AB123CD', 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 102, 'empresa_id' => 8, 'sucursal_id' => 42, 'codigo' => 'AC456EF', 'patente' => 'AC456EF', 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 103, 'empresa_id' => 9, 'sucursal_id' => 91, 'codigo' => 'OTRA1', 'patente' => 'OTRA1', 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 104, 'empresa_id' => 8, 'sucursal_id' => 41, 'codigo' => 'BAJA1', 'patente' => 'BAJA1', 'estado' => 'BAJA', 'deleted_at' => null],
        ]);
        $this->db->table('integraciones_telemetria')->insertBatch([
            ['id' => 201, 'empresa_id' => 8, 'proveedor' => 'wialon', 'nombre' => 'Obersat', 'activo' => 1],
            ['id' => 202, 'empresa_id' => 9, 'proveedor' => 'wialon', 'nombre' => 'Otra', 'activo' => 1],
        ]);
        $this->db->table('equipo_telemetria')->insertBatch([
            ['id' => 1, 'empresa_id' => 8, 'integracion_id' => 201, 'equipo_id' => 101, 'unidad_externa' => 'u-101', 'rol' => 'PRINCIPAL', 'activo' => 1],
            ['id' => 2, 'empresa_id' => 8, 'integracion_id' => 201, 'equipo_id' => 102, 'unidad_externa' => 'u-102', 'rol' => 'PRINCIPAL', 'activo' => 1],
            ['id' => 3, 'empresa_id' => 9, 'integracion_id' => 202, 'equipo_id' => 103, 'unidad_externa' => 'u-103', 'rol' => 'PRINCIPAL', 'activo' => 1],
            ['id' => 4, 'empresa_id' => 8, 'integracion_id' => 201, 'equipo_id' => 104, 'unidad_externa' => 'u-104', 'rol' => 'PRINCIPAL', 'activo' => 1],
        ]);
        $this->db->table('telematia_ultima_lectura')->insert([
            'id' => 301, 'empresa_id' => 8, 'integracion_id' => 201, 'equipo_id' => 101, 'unidad_externa' => 'u-101',
            'proveedor' => 'wialon', 'observada_en' => '2026-10-10 10:00:00', 'registrada_en' => '2026-10-10 10:01:00',
            'latitud' => '-32.9', 'longitud' => '-60.7', 'velocidad_kmh' => '0', 'rumbo' => 90, 'altitud_m' => null,
            'satelites' => 8, 'kilometraje' => 120000, 'horas_decimales' => 9876, 'motor_encendido' => 1,
            'ralenti_activo' => 0, 'voltaje' => '24.5', 'combustible_litros' => '412.0', 'sensores_adicionales' => null,
            'anomalias_sensor' => '[{"sensor":"COMBUSTIBLE","valor":-2,"motivo":"valor imposible","firma":"combustible:-2"}]', 'ausente' => 0,
        ]);

        $this->reader = new CodeIgniterFleetTelemetryBoardReader($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    public function testReturnsOnlyActiveLinkedEquipmentWithinCompanyAndAuthorizedBranches(): void
    {
        $board = $this->reader->read(8, [41]);

        self::assertSame(['AB123CD'], array_column($board['units'], 'code'));
        self::assertSame([['id' => 41, 'name' => 'Rosario']], $board['branches']);
        self::assertSame(-32.9, $board['units'][0]['sources'][0]['position']['latitude']);
        self::assertSame('COMBUSTIBLE', $board['units'][0]['sources'][0]['sensorIssues'][0]['sensor']);
    }

    public function testReturnsNoEquipmentForAnActorWithNoAuthorizedBranches(): void
    {
        self::assertSame(['units' => [], 'branches' => []], $this->reader->read(8, []));
    }
}
