<?php

declare(strict_types=1);

use App\Domain\Geography\InvalidLocalityInput;
use App\Infrastructure\Trips\CodeIgniterTripLocalityCreator;
use App\Infrastructure\Trips\CodeIgniterTripLocalityLookup;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class CodeIgniterTripLocalityCreatorTest extends CIUnitTestCase
{
    private CodeIgniterTripLocalityCreator $creator;
    private CodeIgniterTripLocalityLookup $lookup;

    protected function setUp(): void
    {
        parent::setUp();
        $config = config('Database')->tests;
        if ($config['DBDriver'] !== 'SQLite3') {
            self::markTestSkipped('La prueba usa una base SQLite en memoria aislada.');
        }
        $config['DBPrefix'] = 'loc_create_' . bin2hex(random_bytes(4)) . '_';
        $this->db = db_connect($config, false);
        $table = fn (string $name): string => '`' . $this->db->prefixTable($name) . '`';

        $this->db->query('CREATE TABLE ' . $table('paises') . ' (id INTEGER PRIMARY KEY, nombre VARCHAR(255) NOT NULL, activo INTEGER NOT NULL DEFAULT 1)');
        $this->db->query('CREATE TABLE ' . $table('provincias') . ' (id INTEGER PRIMARY KEY, pais_id INTEGER NOT NULL, nombre VARCHAR(255) NOT NULL, activo INTEGER NOT NULL DEFAULT 1)');
        $this->db->query('CREATE TABLE ' . $table('localidades') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provincia_id INTEGER NOT NULL,
            nombre VARCHAR(255) NOT NULL,
            identificador_fuente VARCHAR(200) NULL,
            fuente VARCHAR(80) NULL,
            codigo_fuente VARCHAR(120) NULL,
            version_fuente VARCHAR(40) NULL,
            contexto_administrativo VARCHAR(255) NULL,
            categoria_fuente TEXT NULL,
            latitud DECIMAL(10,7) NULL,
            longitud DECIMAL(10,7) NULL,
            principal INTEGER NOT NULL DEFAULT 0,
            activo INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');

        $this->db->table('paises')->insert(['id' => 1, 'nombre' => 'Argentina', 'activo' => 1]);
        $this->db->table('provincias')->insertBatch([
            ['id' => 10, 'pais_id' => 1, 'nombre' => 'Misiones', 'activo' => 1],
            ['id' => 20, 'pais_id' => 1, 'nombre' => 'Corrientes', 'activo' => 0],
        ]);

        $this->creator = new CodeIgniterTripLocalityCreator($this->db);
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

    public function testCreatesAManualLocalityVisibleForTripSearch(): void
    {
        $result = $this->creator->findOrCreate(10, 'Capitán Leonidas');

        self::assertTrue($result['created']);
        $row = $this->db->table('localidades')->where('id', $result['id'])->get()->getRowArray();
        self::assertSame('Capitán Leonidas', $row['nombre']);
        self::assertSame(1, (int) $row['principal']);
        self::assertSame(1, (int) $row['activo']);
        self::assertSame('ALTA_MANUAL', $row['fuente']);
        self::assertSame('Capitán Leonidas · Misiones · Argentina', $this->lookup->find($result['id'])['label']);
    }

    public function testReusesAnExistingActiveLocalityAndPromotesItToPrincipal(): void
    {
        $this->db->table('localidades')->insert([
            'provincia_id' => 10,
            'nombre' => 'Posadas',
            'categoria_fuente' => 'Localidad simple',
            'principal' => 0,
            'activo' => 1,
        ]);

        $result = $this->creator->findOrCreate(10, 'posadas');

        self::assertFalse($result['created']);
        self::assertSame(1, $this->db->table('localidades')->countAllResults());
        self::assertSame(1, (int) $this->db->table('localidades')->where('id', $result['id'])->get()->getRowArray()['principal']);
    }

    public function testRejectsAnInactiveOrUnknownProvince(): void
    {
        $this->expectException(InvalidLocalityInput::class);

        $this->creator->findOrCreate(20, 'Mercedes');
    }
}
