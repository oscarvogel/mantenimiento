<?php

declare(strict_types=1);

use App\Infrastructure\Assets\CodeIgniterAssetCatalogReadModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterAssetCatalogReadModelTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresion de catalogos requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE marcas (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, activo INTEGER)');
        $this->database->query('CREATE TABLE tipos_equipo (id INTEGER PRIMARY KEY, nombre TEXT, controla_km INTEGER, controla_horas INTEGER, activo INTEGER)');
        $this->database->query('CREATE TABLE modelos (id INTEGER PRIMARY KEY, empresa_id INTEGER, marca_id INTEGER, tipo_equipo_id INTEGER, nombre TEXT, activo INTEGER)');
        $this->database->table('tipos_equipo')->insert(['id' => 1, 'nombre' => 'Camion', 'controla_km' => 1, 'controla_horas' => 0, 'activo' => 1]);

        for ($id = 1; $id <= 12; $id++) {
            $this->database->table('marcas')->insert(['id' => $id, 'empresa_id' => 5, 'nombre' => sprintf('Marca %02d', $id), 'activo' => 1]);
        }
        $this->database->table('marcas')->insert(['id' => 99, 'empresa_id' => 8, 'nombre' => 'Ajena', 'activo' => 1]);
        for ($id = 1; $id <= 26; $id++) {
            $this->database->table('modelos')->insert(['id' => $id, 'empresa_id' => 5, 'marca_id' => 1, 'tipo_equipo_id' => 1, 'nombre' => sprintf('Modelo %02d', $id), 'activo' => 1]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testClampsIndependentPagesBeforeQueryingTheirItems(): void
    {
        $result = (new CodeIgniterAssetCatalogReadModel($this->database))
            ->paginateManagement(5, 99, 5, 99, 10);

        self::assertSame(3, $result['brands']['page']);
        self::assertSame(3, $result['brands']['totalPages']);
        self::assertCount(2, $result['brands']['items']);
        self::assertSame(3, $result['models']['page']);
        self::assertSame(3, $result['models']['totalPages']);
        self::assertCount(6, $result['models']['items']);
    }
}
