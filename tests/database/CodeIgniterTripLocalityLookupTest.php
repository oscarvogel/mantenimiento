<?php

declare(strict_types=1);

use App\Infrastructure\Trips\CodeIgniterTripLocalityLookup;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class CodeIgniterTripLocalityLookupTest extends CIUnitTestCase
{
    private CodeIgniterTripLocalityLookup $lookup;

    protected function setUp(): void
    {
        parent::setUp();
        $config = config('Database')->tests;
        if ($config['DBDriver'] !== 'SQLite3') {
            self::markTestSkipped('La prueba usa una base SQLite en memoria aislada.');
        }
        $config['DBPrefix'] = 'loc_' . bin2hex(random_bytes(4)) . '_';
        $this->db = db_connect($config, false);
        $table = fn (string $name): string => '`' . $this->db->prefixTable($name) . '`';

        $this->db->query('CREATE TABLE ' . $table('paises') . ' (id INTEGER PRIMARY KEY, nombre VARCHAR(255) NOT NULL, activo INTEGER NOT NULL DEFAULT 1)');
        $this->db->query('CREATE TABLE ' . $table('provincias') . ' (id INTEGER PRIMARY KEY, pais_id INTEGER NOT NULL, nombre VARCHAR(255) NOT NULL, activo INTEGER NOT NULL DEFAULT 1)');
        $this->db->query('CREATE TABLE ' . $table('localidades') . ' (id INTEGER PRIMARY KEY, provincia_id INTEGER NOT NULL, nombre VARCHAR(255) NOT NULL, contexto_administrativo VARCHAR(255), categoria_fuente TEXT, principal INTEGER NOT NULL DEFAULT 0, activo INTEGER NOT NULL DEFAULT 1)');

        $this->db->table('paises')->insertBatch([
            ['id' => 1, 'nombre' => 'Argentina', 'activo' => 1],
            ['id' => 2, 'nombre' => 'Brasil', 'activo' => 1],
        ]);
        $this->db->table('provincias')->insertBatch([
            ['id' => 10, 'pais_id' => 1, 'nombre' => 'San Juan', 'activo' => 1],
            ['id' => 20, 'pais_id' => 2, 'nombre' => 'São Paulo', 'activo' => 1],
        ]);
        $this->db->table('localidades')->insertBatch([
            ['id' => 100, 'provincia_id' => 10, 'nombre' => 'San Juan', 'contexto_administrativo' => null, 'categoria_fuente' => 'PROVINCIA', 'principal' => 1, 'activo' => 1],
            ['id' => 101, 'provincia_id' => 10, 'nombre' => 'San Juan', 'contexto_administrativo' => 'Capital', 'categoria_fuente' => 'Localidad simple', 'principal' => 0, 'activo' => 1],
            ['id' => 102, 'provincia_id' => 20, 'nombre' => 'São Paulo', 'contexto_administrativo' => 'São Paulo', 'categoria_fuente' => 'Cidade / Sede Municipal', 'principal' => 1, 'activo' => 1],
        ]);

        $this->lookup = new CodeIgniterTripLocalityLookup($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            foreach (['localidades', 'provincias', 'paises'] as $table) {
                $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
            }
            $this->db->close();
        }
        parent::tearDown();
    }

    public function testSearchOnlyReturnsPrincipalLocalitiesAndShortensRepeatedLabels(): void
    {
        $results = $this->lookup->search('san juan');

        self::assertCount(1, $results);
        self::assertSame(100, $results[0]['id']);
        self::assertSame('San Juan · Argentina', $results[0]['label']);

        $brazil = $this->lookup->search('são paulo');
        self::assertCount(1, $brazil);
        self::assertSame(102, $brazil[0]['id']);
        self::assertSame('São Paulo · Brasil', $brazil[0]['label']);
    }

    public function testFindStillResolvesAnActiveLocalityThatIsNotPrincipal(): void
    {
        $locality = $this->lookup->find(101);

        self::assertNotNull($locality);
        self::assertSame('San Juan · Capital · Argentina', $locality['label']);
    }
}
