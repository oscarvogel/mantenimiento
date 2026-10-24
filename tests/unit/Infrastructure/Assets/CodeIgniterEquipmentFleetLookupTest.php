<?php

declare(strict_types=1);

use App\Infrastructure\Assets\CodeIgniterEquipmentFleetLookup;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterEquipmentFleetLookupTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La consulta de flota requiere sqlite3.');
        }
        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE tipos_equipo (id INTEGER PRIMARY KEY, nombre TEXT)');
        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, codigo TEXT, patente TEXT, estado TEXT, tipo_equipo_id INTEGER, deleted_at TEXT)');
        $this->database->table('tipos_equipo')->insertBatch([
            ['id' => 1, 'nombre' => 'Camión'],
            ['id' => 2, 'nombre' => 'Semirremolque'],
            ['id' => 3, 'nombre' => 'Utilitario'],
        ]);
        $this->database->table('equipos')->insertBatch([
            ['id' => 10, 'empresa_id' => 7, 'sucursal_id' => 3, 'codigo' => 'CAM-01', 'patente' => 'AA123BB', 'estado' => 'ACTIVO', 'tipo_equipo_id' => 1, 'deleted_at' => null],
            ['id' => 11, 'empresa_id' => 7, 'sucursal_id' => 3, 'codigo' => 'SEM-01', 'patente' => 'CC789DD', 'estado' => 'ACTIVO', 'tipo_equipo_id' => 2, 'deleted_at' => null],
            ['id' => 12, 'empresa_id' => 8, 'sucursal_id' => 3, 'codigo' => 'CAM-02', 'patente' => 'ZZ999XX', 'estado' => 'ACTIVO', 'tipo_equipo_id' => 1, 'deleted_at' => null],
            ['id' => 13, 'empresa_id' => 7, 'sucursal_id' => 3, 'codigo' => 'CAM-03', 'patente' => 'BAUDO', 'estado' => 'BAJA', 'tipo_equipo_id' => 1, 'deleted_at' => '2026-09-01 10:00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->database->close();
    }

    public function testResuelveElEquipoConSuRamoYLaEtiqueta(): void
    {
        $lookup = new CodeIgniterEquipmentFleetLookup($this->database);

        $item = $lookup->equipment(7, 11);

        self::assertNotNull($item);
        self::assertSame(11, $item['id']);
        self::assertSame(3, $item['branchId']);
        self::assertSame('CC789DD · SEM-01', $item['label']);
        self::assertSame('CC789DD', $item['plate']);
        self::assertSame('SEM-01', $item['code']);
        self::assertTrue($item['trailer']);
        self::assertFalse($item['tractor']);
    }

    public function testUnCamionEsTractorYNoAcoplado(): void
    {
        $lookup = new CodeIgniterEquipmentFleetLookup($this->database);

        $item = $lookup->equipment(7, 10);

        self::assertNotNull($item);
        self::assertTrue($item['tractor']);
        self::assertFalse($item['trailer']);
    }

    public function testNoResuelveEquiposDeOtraEmpresa(): void
    {
        $lookup = new CodeIgniterEquipmentFleetLookup($this->database);

        self::assertNull($lookup->equipment(7, 12));
    }

    public function testNoResuelveEquiposDadosDeBaja(): void
    {
        $lookup = new CodeIgniterEquipmentFleetLookup($this->database);

        self::assertNull($lookup->equipment(7, 13));
    }

    public function testResuelvePorPatente(): void
    {
        $lookup = new CodeIgniterEquipmentFleetLookup($this->database);

        $item = $lookup->equipmentByPlate(7, 'AA123BB');

        self::assertNotNull($item);
        self::assertSame(10, $item['id']);
        self::assertTrue($item['tractor']);
    }

    public function testUnaPatenteAjenaNoResuelve(): void
    {
        $lookup = new CodeIgniterEquipmentFleetLookup($this->database);

        self::assertNull($lookup->equipmentByPlate(7, 'ZZ999XX'));
        self::assertNull($lookup->equipmentByPlate(7, '   '));
        self::assertNull($lookup->equipmentByPlate(7, ''));
    }
}
