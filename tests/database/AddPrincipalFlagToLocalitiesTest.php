<?php

declare(strict_types=1);

use App\Database\Migrations\AddPrincipalFlagToLocalities;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class AddPrincipalFlagToLocalitiesTest extends CIUnitTestCase
{
    private AddPrincipalFlagToLocalities $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $config = config('Database')->tests;
        if ($config['DBDriver'] !== 'SQLite3') {
            self::markTestSkipped('La prueba usa una base SQLite en memoria aislada.');
        }
        require_once APPPATH . 'Database/Migrations/2026-11-06-100000_AddPrincipalFlagToLocalities.php';
        $config['DBPrefix'] = 'principal_' . bin2hex(random_bytes(4)) . '_';
        $config['DBDebug'] = true;
        $this->db = db_connect($config, false);

        $table = fn (string $name): string => '`' . $this->db->prefixTable($name) . '`';
        $this->db->query('CREATE TABLE ' . $table('paises') . ' (id INTEGER PRIMARY KEY, codigo VARCHAR(50) NOT NULL, nombre VARCHAR(255) NOT NULL, activo INTEGER NOT NULL DEFAULT 1)');
        $this->db->query('CREATE TABLE ' . $table('provincias') . ' (id INTEGER PRIMARY KEY, pais_id INTEGER NOT NULL, nombre VARCHAR(255) NOT NULL, codigo_fuente VARCHAR(120), activo INTEGER NOT NULL DEFAULT 1)');
        $this->db->query('CREATE TABLE ' . $table('localidades') . ' (id INTEGER PRIMARY KEY, provincia_id INTEGER NOT NULL, nombre VARCHAR(255) NOT NULL, activo INTEGER NOT NULL DEFAULT 1, created_at DATETIME, updated_at DATETIME, identificador_fuente VARCHAR(200), fuente VARCHAR(80), codigo_fuente VARCHAR(120), version_fuente VARCHAR(40), contexto_administrativo VARCHAR(255), categoria_fuente TEXT, latitud DECIMAL(10,7), longitud DECIMAL(10,7))');
        $this->db->query('CREATE TABLE ' . $table('viaje_operativo_tramos') . ' (id INTEGER PRIMARY KEY, localidad_origen_id INTEGER, localidad_destino_id INTEGER)');

        $this->db->table('paises')->insertBatch([
            ['id' => 1, 'codigo' => 'ARGENTINA', 'nombre' => 'Argentina'],
            ['id' => 2, 'codigo' => 'BRASIL', 'nombre' => 'Brasil'],
            ['id' => 3, 'codigo' => 'CHILE', 'nombre' => 'Chile'],
            ['id' => 4, 'codigo' => 'URUGUAY', 'nombre' => 'Uruguay'],
        ]);
        $this->db->table('provincias')->insertBatch([
            ['id' => 10, 'pais_id' => 1, 'nombre' => 'San Juan', 'codigo_fuente' => '70'],
            ['id' => 20, 'pais_id' => 2, 'nombre' => 'São Paulo', 'codigo_fuente' => '35'],
            ['id' => 30, 'pais_id' => 3, 'nombre' => 'Tarapacá', 'codigo_fuente' => '1'],
            ['id' => 40, 'pais_id' => 4, 'nombre' => 'Montevideo', 'codigo_fuente' => '01'],
        ]);
        $this->db->table('localidades')->insertBatch([
            ['id' => 100, 'provincia_id' => 10, 'nombre' => 'San Juan', 'codigo_fuente' => '70000000', 'categoria_fuente' => 'Localidad simple'],
            ['id' => 101, 'provincia_id' => 20, 'nombre' => 'São Paulo', 'codigo_fuente' => '3550308', 'categoria_fuente' => 'Cidade / Capital Estadual | Cidade / Sede Municipal'],
            ['id' => 102, 'provincia_id' => 20, 'nombre' => 'Alta Floresta', 'codigo_fuente' => '1100015', 'categoria_fuente' => 'Cidade / Sede Municipal'],
            ['id' => 103, 'provincia_id' => 30, 'nombre' => 'Iquique', 'codigo_fuente' => '3599', 'categoria_fuente' => 'CIUDAD'],
            ['id' => 104, 'provincia_id' => 30, 'nombre' => 'Baquedano', 'codigo_fuente' => '3600', 'categoria_fuente' => 'ALDEA'],
            ['id' => 105, 'provincia_id' => 40, 'nombre' => 'Montevideo', 'codigo_fuente' => 'UYMOMVD', 'categoria_fuente' => 'LOCALIDAD CENSAL'],
        ]);

        $this->migration = new AddPrincipalFlagToLocalities(Database::forge($this->db));
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            foreach (['viaje_operativo_tramos', 'localidades', 'provincias', 'paises'] as $table) {
                $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
            }
            $this->db->close();
        }
        parent::tearDown();
    }

    public function testUpShowsOnlyThePrincipalCatalogAndAddsArgentinaProvinceEntries(): void
    {
        $this->migration->up();

        self::assertTrue($this->db->fieldExists('principal', 'localidades'));
        $byId = [];
        foreach ($this->db->table('localidades')->get()->getResultArray() as $row) {
            $byId[(int) $row['id']] = $row;
        }

        self::assertSame(100, (int) $byId[100]['id']);
        self::assertSame(0, (int) $byId[100]['principal']);
        self::assertSame(1, (int) $byId[101]['principal']);
        self::assertSame(0, (int) $byId[102]['principal']);
        self::assertSame(1, (int) $byId[103]['principal']);
        self::assertSame(0, (int) $byId[104]['principal']);
        self::assertSame(1, (int) $byId[105]['principal']);

        $province = $this->db->table('localidades')
            ->where('categoria_fuente', 'PROVINCIA')
            ->get()->getRowArray();
        self::assertNotNull($province);
        self::assertSame('San Juan', $province['nombre']);
        self::assertSame(10, (int) $province['provincia_id']);
        self::assertSame(1, (int) $province['principal']);
    }

    public function testDownKeepsReferencedProvinceEntriesAndRemovesTheFlag(): void
    {
        $this->migration->up();
        $province = $this->db->table('localidades')->where('categoria_fuente', 'PROVINCIA')->get()->getRowArray();
        self::assertNotNull($province);
        $this->db->table('viaje_operativo_tramos')->insert(['id' => 500, 'localidad_origen_id' => (int) $province['id']]);

        $this->migration->down();

        self::assertFalse($this->db->fieldExists('principal', 'localidades'));
        $kept = $this->db->table('localidades')->where('id', (int) $province['id'])->get()->getRowArray();
        self::assertNotNull($kept);
        self::assertSame(0, (int) $kept['activo']);
        self::assertSame(1, $this->db->table('viaje_operativo_tramos')->where('id', 500)->countAllResults());
    }

    public function testDownRemovesUnreferencedProvinceEntries(): void
    {
        $this->migration->up();
        $this->migration->down();

        self::assertFalse($this->db->fieldExists('principal', 'localidades'));
        self::assertSame(0, $this->db->table('localidades')->where('categoria_fuente', 'PROVINCIA')->countAllResults());
    }
}
