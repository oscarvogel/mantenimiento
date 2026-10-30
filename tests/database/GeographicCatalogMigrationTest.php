<?php

declare(strict_types=1);

use App\Database\Migrations\ExtendGeographicCatalogs;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class GeographicCatalogMigrationTest extends CIUnitTestCase
{
    private ?ExtendGeographicCatalogs $migration = null;

    protected function setUp(): void
    {
        parent::setUp();
        $config = config('Database')->tests;
        if ($config['DBDriver'] !== 'SQLite3') {
            self::markTestSkipped('La prueba usa una base SQLite en memoria aislada.');
        }
        require_once APPPATH . 'Database/Migrations/2026-10-30-130000_ExtendGeographicCatalogs.php';
        $config['DBPrefix'] = 'geo_' . bin2hex(random_bytes(4)) . '_';
        $config['DBDebug'] = true;
        $this->db = db_connect($config, false);

        $this->db->query('CREATE TABLE ' . $this->table('paises') . ' (id INTEGER PRIMARY KEY, codigo VARCHAR(50) NOT NULL, nombre VARCHAR(255) NOT NULL, activo INTEGER NOT NULL DEFAULT 1)');
        $this->db->query('CREATE TABLE ' . $this->table('usuarios') . ' (id INTEGER PRIMARY KEY)');
        $this->db->query('CREATE TABLE ' . $this->table('provincias') . ' (id INTEGER PRIMARY KEY, pais_id INTEGER NOT NULL, nombre VARCHAR(255) NOT NULL, activo INTEGER NOT NULL DEFAULT 1, FOREIGN KEY (pais_id) REFERENCES ' . $this->table('paises') . '(id))');
        $this->db->query('CREATE TABLE ' . $this->table('localidades') . ' (id INTEGER PRIMARY KEY, provincia_id INTEGER NOT NULL, nombre VARCHAR(255) NOT NULL, activo INTEGER NOT NULL DEFAULT 1, FOREIGN KEY (provincia_id) REFERENCES ' . $this->table('provincias') . '(id))');
        $this->db->query('CREATE TABLE ' . $this->table('viaje_operativo_tramos') . ' (id INTEGER PRIMARY KEY, localidad_origen_id INTEGER NOT NULL, FOREIGN KEY (localidad_origen_id) REFERENCES ' . $this->table('localidades') . '(id))');

        $this->db->table('paises')->insert(['id' => 1, 'codigo' => 'ARGENTINA', 'nombre' => 'Argentina']);
        $this->db->table('usuarios')->insert(['id' => 10]);
        $this->db->table('provincias')->insert(['id' => 20, 'pais_id' => 1, 'nombre' => 'Buenos Aires']);
        $this->db->table('localidades')->insert(['id' => 30, 'provincia_id' => 20, 'nombre' => 'Buenos Aires']);
        $this->db->table('viaje_operativo_tramos')->insert(['id' => 40, 'localidad_origen_id' => 30]);
        $this->migration = new ExtendGeographicCatalogs(Database::forge($this->db));
    }

    protected function tearDown(): void
    {
        if ($this->migration !== null) {
            foreach (['geografia_importaciones', 'viaje_operativo_tramos', 'localidades', 'provincias', 'usuarios', 'paises'] as $table) {
                $this->db->query('DROP TABLE IF EXISTS ' . $this->table($table));
            }
            $this->db->close();
        }
        parent::tearDown();
    }

    public function testUpPreservesLocalityAndTripIdsAndEnforcesGlobalSourceIdentity(): void
    {
        $this->migration->up();

        self::assertSame(1, $this->db->table('localidades')->countAllResults());
        self::assertSame(30, (int) $this->db->table('localidades')->get()->getRowArray()['id']);
        self::assertSame(30, (int) $this->db->table('viaje_operativo_tramos')->get()->getRowArray()['localidad_origen_id']);
        self::assertNull($this->db->table('localidades')->get()->getRowArray()['identificador_fuente']);

        $this->db->table('localidades')->insert([
            'id' => 31,
            'provincia_id' => 20,
            'nombre' => 'Ampere',
            'identificador_fuente' => 'ARGENTINA:GEOREF_V2:30008050',
            'fuente' => 'GEOREF_V2',
            'codigo_fuente' => '30008050',
            'latitud' => '-32.0883131',
            'longitud' => '-58.3394885',
        ]);
        $this->expectException(DatabaseException::class);
        $this->db->table('localidades')->insert([
            'id' => 32,
            'provincia_id' => 20,
            'nombre' => 'Duplicada',
            'identificador_fuente' => 'ARGENTINA:GEOREF_V2:30008050',
        ]);
    }

    public function testDownRefusesToDeleteImportedLocalityMetadata(): void
    {
        $this->migration->up();
        $this->db->table('localidades')->where('id', 30)->update([
            'identificador_fuente' => 'ARGENTINA:GEOREF_V2:30008050',
        ]);
        $this->db->resetDataCache();
        self::assertSame('ARGENTINA:GEOREF_V2:30008050', $this->db->table('localidades')->where('id', 30)->get()->getRowArray()['identificador_fuente']);
        self::assertSame(1, $this->db->table('localidades')->where('identificador_fuente IS NOT NULL', null, false)->countAllResults());
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) AS total FROM ' . $this->db->protectIdentifiers('localidades', true) . ' WHERE identificador_fuente IS NOT NULL')->getRowArray()['total']);
        self::assertTrue($this->db->fieldExists('identificador_fuente', 'localidades'));

        try {
            $this->migration->down();
            self::fail('No debe eliminarse la identidad de una localidad importada.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('localidades o provincias importadas', $exception->getMessage());
        }
        self::assertTrue($this->db->fieldExists('identificador_fuente', 'localidades'));
    }

    public function testDownRefusesToDeleteImportHistoryAndReversesEmptySchema(): void
    {
        $this->migration->up();
        $this->db->table('geografia_importaciones')->insert([
            'pais_id' => 1,
            'fuente' => 'GEOREF_V2',
            'version_fuente' => '2026-04',
            'sha256_archivo' => str_repeat('a', 64),
            'filas_recibidas' => 1,
            'localidades_nuevas' => 1,
            'usuario_id' => 10,
            'created_at' => '2026-10-30 12:00:00',
        ]);
        try {
            $this->migration->down();
            self::fail('No debe eliminarse el historial de importaciones.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('historial de importaciones', $exception->getMessage());
        }
        self::assertTrue($this->db->tableExists('geografia_importaciones'));
    }

    public function testDownReversesSchemaWhenNoGeographicRecordsWereImported(): void
    {
        $this->migration->up();
        $this->migration->down();

        self::assertFalse($this->db->fieldExists('identificador_fuente', 'localidades'));
        self::assertFalse($this->db->fieldExists('latitud', 'localidades'));
        self::assertFalse($this->db->tableExists('geografia_importaciones'));
        self::assertSame(30, (int) $this->db->table('viaje_operativo_tramos')->get()->getRowArray()['localidad_origen_id']);
    }

    private function table(string $table): string
    {
        return '`' . $this->db->prefixTable($table) . '`';
    }
}
