<?php

declare(strict_types=1);

use App\Database\Migrations\AllowSameNameLocalities;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class AllowSameNameLocalitiesTest extends CIUnitTestCase
{
    private ?AllowSameNameLocalities $migration = null;

    protected function setUp(): void
    {
        parent::setUp();
        $config = config('Database')->tests;
        if ($config['DBDriver'] !== 'SQLite3') {
            self::markTestSkipped('La prueba usa una base SQLite en memoria aislada.');
        }
        require_once APPPATH . 'Database/Migrations/2026-10-31-080000_AllowSameNameLocalities.php';
        $config['DBPrefix'] = 'same_name_' . bin2hex(random_bytes(4)) . '_';
        $config['DBDebug'] = true;
        $this->db = db_connect($config, false);
        $table = '`' . $this->db->prefixTable('localidades') . '`';
        $this->db->query('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, provincia_id INTEGER NOT NULL, nombre VARCHAR(255) NOT NULL, identificador_fuente VARCHAR(200) UNIQUE)');
        $this->db->query('CREATE UNIQUE INDEX uq_localidades_provincia_nombre ON ' . $table . ' (provincia_id, nombre)');
        $this->migration = new AllowSameNameLocalities(Database::forge($this->db));
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('localidades') . '`');
            $this->db->close();
        }
        parent::tearDown();
    }

    public function testUpAllowsHomonymousSourceLocalitiesAndDownProtectsThem(): void
    {
        $this->migration->up();
        $this->db->table('localidades')->insertBatch([
            ['provincia_id' => 6, 'nombre' => 'Paso del Durazno', 'identificador_fuente' => 'ARGENTINA:GEOREF_V2:14056140'],
            ['provincia_id' => 6, 'nombre' => 'Paso del Durazno', 'identificador_fuente' => 'ARGENTINA:GEOREF_V2:14098220'],
        ]);

        self::assertSame(2, $this->db->table('localidades')->countAllResults());
        try {
            $this->migration->down();
            self::fail('No debe restaurarse la restricción por nombre si existen localidades homónimas.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('localidades homónimas', $exception->getMessage());
        }
        self::assertSame(2, $this->db->table('localidades')->countAllResults());
    }

    public function testDownRestoresNameUniquenessWhenSafe(): void
    {
        $this->migration->up();
        $this->migration->down();
        $this->db->table('localidades')->insert([
            'provincia_id' => 6, 'nombre' => 'Paso del Durazno', 'identificador_fuente' => 'ARGENTINA:GEOREF_V2:14056140',
        ]);

        $this->expectException(DatabaseException::class);
        $this->db->table('localidades')->insert([
            'provincia_id' => 6, 'nombre' => 'Paso del Durazno', 'identificador_fuente' => 'ARGENTINA:GEOREF_V2:14098220',
        ]);
    }
}
