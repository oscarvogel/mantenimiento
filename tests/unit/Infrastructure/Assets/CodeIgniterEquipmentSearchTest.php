<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Assets;

use App\Infrastructure\Assets\CodeIgniterEquipmentSearch;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class CodeIgniterEquipmentSearchTest extends CIUnitTestCase
{
    private $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La búsqueda de equipos requiere sqlite3.');
        }

        parent::setUp();
        $this->database = Database::connect('tests');
        $this->database->setPrefix('');
        $this->dropTables();
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo VARCHAR(20), nombre VARCHAR(255))');
        $this->database->query('CREATE TABLE tipos_equipo (id INTEGER PRIMARY KEY, nombre VARCHAR(100), controla_km TINYINT, controla_horas TINYINT)');
        $this->database->query('CREATE TABLE marcas (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre VARCHAR(100))');
        $this->database->query('CREATE TABLE modelos (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre VARCHAR(100))');
        $this->database->query('CREATE TABLE lecturas_equipo (id INTEGER PRIMARY KEY, empresa_id INTEGER, equipo_id INTEGER, anulada TINYINT, fecha_lectura DATETIME)');
        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, tipo_equipo_id INTEGER, marca_id INTEGER NULL, modelo_id INTEGER NULL, codigo VARCHAR(50), patente VARCHAR(20) NULL, anio INTEGER NULL, chasis VARCHAR(50) NULL, motor VARCHAR(50) NULL, km_actual BIGINT NULL, horas_actuales DECIMAL(12,2) NULL, estado VARCHAR(20), fecha_alta DATE, fecha_baja DATE NULL, deleted_at DATETIME NULL)');

        $this->database->table('sucursales')->insert(['id' => 10, 'empresa_id' => 5, 'codigo' => 'CC', 'nombre' => 'Casa Central']);
        $this->database->table('tipos_equipo')->insert(['id' => 1, 'nombre' => 'Tractor', 'controla_km' => 1, 'controla_horas' => 0]);
        foreach ([[1, 'TR-12', 'AB123CD'], [2, 'AC-07', null], [3, 'X%1', null]] as [$id, $code, $plate]) {
            $this->database->table('equipos')->insert([
                'id' => $id, 'empresa_id' => 5, 'sucursal_id' => 10, 'tipo_equipo_id' => 1, 'codigo' => $code,
                'patente' => $plate, 'estado' => 'ACTIVO', 'fecha_alta' => '2026-01-01', 'deleted_at' => null,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        $this->database->resetDataCache();
        parent::tearDown();
    }

    // Regresión: CodeIgniter no escapa % ni _ dentro de like(); "%%" devolvía toda la flota.
    public function testWildcardsAreSearchedLiterally(): void
    {
        $search = new CodeIgniterEquipmentSearch($this->database);
        $codes = fn (string $query): array => array_column($search->search(5, null, $query, null, null, null, null, 1, 10)['items'], 'codigo');

        self::assertSame([], $codes('%%'));
        self::assertSame([], $codes('__'));
        self::assertSame(['X%1'], $codes('%1'));
        self::assertSame(['TR-12'], $codes('ab123'));
        self::assertSame(0, $search->search(5, null, '%%', null, null, null, null, 1, 10)['total']);
    }

    private function dropTables(): void
    {
        foreach (['equipos', 'lecturas_equipo', 'modelos', 'marcas', 'tipos_equipo', 'sucursales'] as $table) {
            $this->database->query('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
