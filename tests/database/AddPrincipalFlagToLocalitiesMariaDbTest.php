<?php

declare(strict_types=1);

use App\Database\Migrations\AddPrincipalFlagToLocalities;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * Regresión de la instalación desde cero sobre MariaDB real (#146).
 *
 * La prueba en SQLite no alcanza: SQLite acepta `provincia_id IN ()` y por
 * eso el defecto nunca apareció en la suite normal. MySQL y MariaDB lo
 * rechazan, y con eso la instalación desde cero se cortaba en la migración
 * `2026-11-06-100000`.
 *
 * Se ejecuta sólo cuando el grupo `tests` apunta a la base descartable
 * `mantenimiento_test` de fasa_189; en la suite normal queda omitido.
 */
final class AddPrincipalFlagToLocalitiesMariaDbTest extends CIUnitTestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        parent::setUp();
        $config = config('Database')->tests;
        if (($config['DBDriver'] ?? '') !== 'MySQLi' || ! preg_match('/^mantenimiento_test$/', (string) ($config['database'] ?? ''))) {
            self::markTestSkipped('Requiere MariaDB descartable mantenimiento_test en fasa_189.');
        }

        $config['DBPrefix'] = 'principal_maria_' . bin2hex(random_bytes(4)) . '_';
        $config['DBDebug'] = true;
        $this->database = db_connect($config, false);

        $table = fn (string $name): string => '`' . $this->database->prefixTable($name) . '`';
        $this->database->query('CREATE TABLE ' . $table('paises') . ' (id INT PRIMARY KEY, codigo VARCHAR(50) NOT NULL, nombre VARCHAR(255) NOT NULL, activo TINYINT NOT NULL DEFAULT 1)');
        $this->database->query('CREATE TABLE ' . $table('provincias') . ' (id INT PRIMARY KEY, pais_id INT NOT NULL, nombre VARCHAR(255) NOT NULL, codigo_fuente VARCHAR(120) NULL, activo TINYINT NOT NULL DEFAULT 1)');
        $this->database->query('CREATE TABLE ' . $table('localidades') . ' (
            id INT PRIMARY KEY,
            provincia_id INT NOT NULL,
            nombre VARCHAR(255) NOT NULL,
            activo TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            identificador_fuente VARCHAR(200) NULL,
            fuente VARCHAR(80) NULL,
            codigo_fuente VARCHAR(120) NULL,
            version_fuente VARCHAR(40) NULL,
            contexto_administrativo VARCHAR(255) NULL,
            categoria_fuente TEXT NULL,
            latitud DECIMAL(10,7) NULL,
            longitud DECIMAL(10,7) NULL
        )');
        $this->database->query('CREATE TABLE ' . $table('viaje_operativo_tramos') . ' (id INT PRIMARY KEY, localidad_origen_id INT NULL, localidad_destino_id INT NULL)');

        require_once APPPATH . 'Database/Migrations/2026-11-06-100000_AddPrincipalFlagToLocalities.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            foreach (['viaje_operativo_tramos', 'localidades', 'provincias', 'paises'] as $table) {
                $this->database->query('DROP TABLE IF EXISTS `' . $this->database->prefixTable($table) . '`');
            }
            $this->database->close();
        }
        parent::tearDown();
    }

    public function testUpCompletesWhenBrazilHasNoProvincesYet(): void
    {
        // Instalación desde cero: el país existe pero todavía no tiene
        // provincias cargadas. Antes esto generaba `provincia_id IN ()`.
        $this->database->table('paises')->insert(['id' => 2, 'codigo' => 'BRASIL', 'nombre' => 'Brasil', 'activo' => 1]);
        $this->database->table('localidades')->insert([
            'id' => 900, 'provincia_id' => 20, 'nombre' => 'Localidad sin provincia', 'activo' => 1, 'codigo_fuente' => '3550308',
        ]);

        $migration = new AddPrincipalFlagToLocalities(Database::forge($this->database));
        $migration->up();

        self::assertTrue($this->database->fieldExists('principal', 'localidades'));
        self::assertSame(0, (int) $this->database->table('localidades')->where('id', 900)->get()->getRowArray()['principal']);
    }

    public function testUpCompletesWithAnEmptyCatalog(): void
    {
        // Sin países, sin provincias y sin localidades: nada que marcar.
        $migration = new AddPrincipalFlagToLocalities(Database::forge($this->database));
        $migration->up();

        self::assertTrue($this->database->fieldExists('principal', 'localidades'));
        self::assertSame(0, (int) $this->database->table('localidades')->countAllResults());
    }

    public function testUpMarksTheCatalogPrincipalities(): void
    {
        $this->database->table('paises')->insertBatch([
            ['id' => 1, 'codigo' => 'ARGENTINA', 'nombre' => 'Argentina', 'activo' => 1],
            ['id' => 2, 'codigo' => 'BRASIL', 'nombre' => 'Brasil', 'activo' => 1],
            ['id' => 3, 'codigo' => 'CHILE', 'nombre' => 'Chile', 'activo' => 1],
            ['id' => 4, 'codigo' => 'URUGUAY', 'nombre' => 'Uruguay', 'activo' => 1],
        ]);
        $this->database->table('provincias')->insertBatch([
            ['id' => 10, 'pais_id' => 1, 'nombre' => 'San Juan', 'codigo_fuente' => '70', 'activo' => 1],
            ['id' => 20, 'pais_id' => 2, 'nombre' => 'São Paulo', 'codigo_fuente' => '35', 'activo' => 1],
            ['id' => 30, 'pais_id' => 3, 'nombre' => 'Tarapacá', 'codigo_fuente' => '1', 'activo' => 1],
            ['id' => 40, 'pais_id' => 4, 'nombre' => 'Montevideo', 'codigo_fuente' => '01', 'activo' => 1],
        ]);
        $this->database->table('localidades')->insertBatch([
            ['id' => 101, 'provincia_id' => 20, 'nombre' => 'São Paulo', 'codigo_fuente' => '3550308', 'categoria_fuente' => 'Cidade / Capital Estadual', 'activo' => 1],
            ['id' => 103, 'provincia_id' => 30, 'nombre' => 'Iquique', 'codigo_fuente' => '3599', 'categoria_fuente' => 'CIUDAD', 'activo' => 1],
            ['id' => 104, 'provincia_id' => 30, 'nombre' => 'Baquedano', 'codigo_fuente' => '3600', 'categoria_fuente' => 'ALDEA', 'activo' => 1],
            ['id' => 105, 'provincia_id' => 40, 'nombre' => 'Montevideo', 'codigo_fuente' => 'UYMOMVD', 'categoria_fuente' => 'LOCALIDAD CENSAL', 'activo' => 1],
        ]);

        $migration = new AddPrincipalFlagToLocalities(Database::forge($this->database));
        $migration->up();

        $byId = [];
        foreach ($this->database->table('localidades')->get()->getResultArray() as $row) {
            $byId[(int) $row['id']] = (int) $row['principal'];
        }

        self::assertSame(1, $byId[101] ?? 0, 'Municipio principal de Brasil.');
        self::assertSame(1, $byId[103] ?? 0, 'Ciudad de Chile.');
        self::assertSame(0, $byId[104] ?? -1, 'Aldea de Chile no es principal.');
        self::assertSame(1, $byId[105] ?? 0, 'Localidad censal de Uruguay.');
    }
}
