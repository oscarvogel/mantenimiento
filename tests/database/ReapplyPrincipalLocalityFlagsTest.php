<?php

declare(strict_types=1);

use App\Database\Migrations\ReapplyPrincipalLocalityFlags;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * Migración correctiva de la marca `principal` (#146).
 *
 * Garantiza la columna, re-aplica las marcas de la regla sin resetear las que
 * puso el importador del catálogo y es idempotente.
 */
final class ReapplyPrincipalLocalityFlagsTest extends CIUnitTestCase
{
    private ReapplyPrincipalLocalityFlags $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $config = config('Database')->tests;
        if ($config['DBDriver'] !== 'SQLite3') {
            self::markTestSkipped('La prueba usa una base SQLite en memoria aislada.');
        }
        require_once APPPATH . 'Database/Migrations/2026-11-17-100000_ReapplyPrincipalLocalityFlags.php';
        $config['DBPrefix'] = 'reaplica_' . bin2hex(random_bytes(4)) . '_';
        $config['DBDebug'] = true;
        $this->db = db_connect($config, false);

        $table = fn (string $name): string => '`' . $this->db->prefixTable($name) . '`';
        $this->db->query('CREATE TABLE ' . $table('paises') . ' (id INTEGER PRIMARY KEY, codigo VARCHAR(50) NOT NULL, nombre VARCHAR(255) NOT NULL, activo INTEGER NOT NULL DEFAULT 1)');
        $this->db->query('CREATE TABLE ' . $table('provincias') . ' (id INTEGER PRIMARY KEY, pais_id INTEGER NOT NULL, nombre VARCHAR(255) NOT NULL, codigo_fuente VARCHAR(120), activo INTEGER NOT NULL DEFAULT 1)');
        $this->db->query('CREATE TABLE ' . $table('localidades') . ' (id INTEGER PRIMARY KEY, provincia_id INTEGER NOT NULL, nombre VARCHAR(255) NOT NULL, activo INTEGER NOT NULL DEFAULT 1, created_at DATETIME, updated_at DATETIME, identificador_fuente VARCHAR(200), fuente VARCHAR(80), codigo_fuente VARCHAR(120), version_fuente VARCHAR(40), contexto_administrativo VARCHAR(255), categoria_fuente TEXT, latitud DECIMAL(10,7), longitud DECIMAL(10,7))');
        $this->db->query('CREATE TABLE ' . $table('viaje_operativo_tramos') . ' (id INTEGER PRIMARY KEY, localidad_origen_id INTEGER, localidad_destino_id INTEGER)');

        $this->db->table('paises')->insertBatch([
            ['id' => 2, 'codigo' => 'BRASIL', 'nombre' => 'Brasil', 'activo' => 1],
            ['id' => 3, 'codigo' => 'CHILE', 'nombre' => 'Chile', 'activo' => 1],
        ]);
        $this->db->table('provincias')->insertBatch([
            ['id' => 20, 'pais_id' => 2, 'nombre' => 'São Paulo', 'codigo_fuente' => '35', 'activo' => 1],
            ['id' => 30, 'pais_id' => 3, 'nombre' => 'Tarapacá', 'codigo_fuente' => '1', 'activo' => 1],
        ]);
        $this->db->table('localidades')->insertBatch([
            ['id' => 101, 'provincia_id' => 20, 'nombre' => 'São Paulo', 'codigo_fuente' => '3550308', 'categoria_fuente' => 'Cidade / Capital Estadual', 'activo' => 1],
            ['id' => 103, 'provincia_id' => 30, 'nombre' => 'Iquique', 'codigo_fuente' => '3599', 'categoria_fuente' => 'CIUDAD', 'activo' => 1],
            ['id' => 104, 'provincia_id' => 30, 'nombre' => 'Baquedano', 'codigo_fuente' => '3600', 'categoria_fuente' => 'ALDEA', 'activo' => 1],
        ]);

        $this->migration = new ReapplyPrincipalLocalityFlags(Database::forge($this->db));
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

    public function testUpAddsTheColumnWhenItIsMissing(): void
    {
        self::assertFalse($this->db->fieldExists('principal', 'localidades'));

        $this->migration->up();

        self::assertTrue($this->db->fieldExists('principal', 'localidades'));
    }

    public function testUpMarksThePrincipalitiesWithoutResettingTheOthers(): void
    {
        // Una marca previa del importador del catálogo no se pierde.
        $this->db->query('ALTER TABLE `' . $this->db->prefixTable('localidades') . '` ADD COLUMN principal INTEGER NOT NULL DEFAULT 0');
        $this->db->table('localidades')->where('id', 104)->update(['principal' => 1]);

        $this->migration->up();

        self::assertSame(1, (int) $this->db->table('localidades')->where('id', 101)->get()->getRowArray()['principal']);
        self::assertSame(1, (int) $this->db->table('localidades')->where('id', 103)->get()->getRowArray()['principal']);
        self::assertSame(1, (int) $this->db->table('localidades')->where('id', 104)->get()->getRowArray()['principal'], 'La marca previa se conserva.');
    }

    public function testUpIsIdempotent(): void
    {
        $this->migration->up();
        $first = $this->db->table('localidades')->select('id, principal')->get()->getResultArray();
        $this->migration->up();
        $second = $this->db->table('localidades')->select('id, principal')->get()->getResultArray();

        self::assertSame($first, $second);
    }

    public function testDownKeepsTheColumnAndTheMarks(): void
    {
        $this->migration->up();
        $this->migration->down();

        self::assertTrue($this->db->fieldExists('principal', 'localidades'));
        self::assertSame(1, (int) $this->db->table('localidades')->where('id', 101)->get()->getRowArray()['principal']);
    }
}
