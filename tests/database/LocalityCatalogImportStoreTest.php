<?php

declare(strict_types=1);

use App\Infrastructure\Geography\CodeIgniterLocalityCatalogImportStore;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class LocalityCatalogImportStoreTest extends CIUnitTestCase
{
    private CodeIgniterLocalityCatalogImportStore $store;

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
        $this->db->query('CREATE TABLE ' . $table('paises') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, codigo VARCHAR(50) NOT NULL UNIQUE, nombre VARCHAR(255) NOT NULL, activo INTEGER NOT NULL DEFAULT 1, created_at DATETIME, updated_at DATETIME)');
        $this->db->query('CREATE TABLE ' . $table('provincias') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, pais_id INTEGER NOT NULL, nombre VARCHAR(255) NOT NULL, activo INTEGER NOT NULL DEFAULT 1, created_at DATETIME, updated_at DATETIME, identificador_fuente VARCHAR(200) UNIQUE, fuente VARCHAR(80), codigo_fuente VARCHAR(120), UNIQUE (pais_id,nombre), FOREIGN KEY (pais_id) REFERENCES ' . $table('paises') . '(id))');
        $this->db->query('CREATE TABLE ' . $table('localidades') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, provincia_id INTEGER NOT NULL, nombre VARCHAR(255) NOT NULL, activo INTEGER NOT NULL DEFAULT 1, created_at DATETIME, updated_at DATETIME, identificador_fuente VARCHAR(200) UNIQUE, fuente VARCHAR(80), codigo_fuente VARCHAR(120), version_fuente VARCHAR(40), contexto_administrativo VARCHAR(255), categoria_fuente TEXT, latitud DECIMAL(10,7), longitud DECIMAL(10,7), UNIQUE (provincia_id,nombre), FOREIGN KEY (provincia_id) REFERENCES ' . $table('provincias') . '(id))');
        $this->db->query('CREATE TABLE ' . $table('geografia_importaciones') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, pais_id INTEGER, fuente VARCHAR(80), version_fuente VARCHAR(40), sha256_archivo CHAR(64), filas_recibidas INTEGER, localidades_nuevas INTEGER, localidades_actualizadas INTEGER, localidades_sin_cambios INTEGER, usuario_id INTEGER, created_at DATETIME)');
        $this->db->table('paises')->insertBatch([
            ['codigo' => 'ARGENTINA', 'nombre' => 'Argentina', 'activo' => 1],
            ['codigo' => 'BRASIL', 'nombre' => 'Brasil', 'activo' => 1],
            ['codigo' => 'CHILE', 'nombre' => 'Chile', 'activo' => 1],
            ['codigo' => 'URUGUAY', 'nombre' => 'Uruguay', 'activo' => 1],
        ]);
        $this->store = new CodeIgniterLocalityCatalogImportStore($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            foreach (['geografia_importaciones', 'localidades', 'provincias', 'paises'] as $table) {
                $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
            }
            $this->db->close();
        }
        parent::tearDown();
    }

    public function testImportIsIdempotentAndUpdatesInPlace(): void
    {
        $records = [$this->record()];
        $first = $this->store->import('ARGENTINA', 'GEOREF_V2', '2026-04', str_repeat('a', 64), 7, $records);
        $localityId = (int) $this->db->table('localidades')->get()->getRowArray()['id'];
        $second = $this->store->import('ARGENTINA', 'GEOREF_V2', '2026-04', str_repeat('a', 64), 7, $records);
        $changed = $this->store->import('ARGENTINA', 'GEOREF_V2', '2026-04', str_repeat('b', 64), 7, [$this->record('Ampere actualizado')]);

        self::assertSame(['nuevas' => 1, 'actualizadas' => 0, 'sin_cambios' => 0], $first);
        self::assertSame(['nuevas' => 0, 'actualizadas' => 0, 'sin_cambios' => 1], $second);
        self::assertSame(['nuevas' => 0, 'actualizadas' => 1, 'sin_cambios' => 0], $changed);
        self::assertSame(1, $this->db->table('localidades')->countAllResults());
        $locality = $this->db->table('localidades')->get()->getRowArray();
        self::assertSame($localityId, (int) $locality['id']);
        self::assertSame('Ampere actualizado', $locality['nombre']);
        self::assertSame(3, $this->db->table('geografia_importaciones')->countAllResults());
    }

    public function testDatabaseFailureRollsBackTheWholeBatchAndAudit(): void
    {
        $table = '`' . $this->db->prefixTable('localidades') . '`';
        $this->db->query("CREATE TRIGGER fail_locality BEFORE INSERT ON {$table} WHEN NEW.nombre = 'Forzar error' BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");
        $records = [$this->record(), $this->record('Forzar error', '30008051')];
        try {
            $this->store->import('ARGENTINA', 'GEOREF_V2', '2026-04', str_repeat('c', 64), 7, $records);
            self::fail('El código duplicado debe fallar en el índice único.');
        } catch (Throwable) {
            self::assertSame(0, $this->db->table('provincias')->countAllResults());
            self::assertSame(0, $this->db->table('localidades')->countAllResults());
            self::assertSame(0, $this->db->table('geografia_importaciones')->countAllResults());
        }
    }

    public function testLargeBatchUsesStableRowsAcrossMultipleInsertChunks(): void
    {
        $records = [];
        for ($index = 1; $index <= 160; $index++) {
            $records[] = $this->record('Localidad ' . $index, (string) (30008050 + $index));
        }

        $result = $this->store->import('ARGENTINA', 'GEOREF_V2', '2026-04', str_repeat('d', 64), 7, $records);

        self::assertSame(['nuevas' => 160, 'actualizadas' => 0, 'sin_cambios' => 0], $result);
        self::assertSame(160, $this->db->table('localidades')->countAllResults());
        self::assertSame(160, $this->db->table('localidades')->where('identificador_fuente IS NOT NULL', null, false)->countAllResults());
    }

    private function record(string $name = 'Ampere', string $code = '30008050'): array
    {
        return [
            'pais_codigo' => 'ARGENTINA', 'fuente' => 'GEOREF_V2', 'codigo_provincia' => '30',
            'provincia' => 'Entre Ríos', 'codigo_localidad' => $code, 'localidad' => $name,
            'contexto' => 'Colón', 'categoria' => 'Localidad simple', 'latitud' => '-32.0883131',
            'longitud' => '-58.3394885', 'version' => '2026-04',
        ];
    }
}
