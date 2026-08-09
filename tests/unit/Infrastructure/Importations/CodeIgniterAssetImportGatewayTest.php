<?php

declare(strict_types=1);

use App\Application\Importations\AssetImportData;
use App\Infrastructure\Importations\CodeIgniterAssetImportGateway;
use App\Infrastructure\Importations\CodeIgniterImportReferenceGateway;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterAssetImportGatewayTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresion del gateway requiere sqlite3.');
        }
        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true, 'foreignKeys' => true,
        ], false);
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo TEXT, estado INTEGER, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE tipos_equipo (id INTEGER PRIMARY KEY, nombre TEXT, controla_km INTEGER, controla_horas INTEGER, activo INTEGER)');
        $this->database->query('CREATE TABLE marcas (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, activo INTEGER)');
        $this->database->query('CREATE TABLE modelos (id INTEGER PRIMARY KEY, empresa_id INTEGER, marca_id INTEGER, tipo_equipo_id INTEGER, nombre TEXT, activo INTEGER)');
        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER, sucursal_id INTEGER, tipo_equipo_id INTEGER, codigo TEXT, patente TEXT NULL, marca_id INTEGER NULL, modelo_id INTEGER NULL, anio INTEGER NULL, chasis TEXT NULL, motor TEXT NULL, km_actual INTEGER NULL, horas_actuales TEXT NULL, estado TEXT, fecha_alta TEXT, observaciones TEXT NULL, created_at TEXT, updated_at TEXT, created_by INTEGER, updated_by INTEGER, deleted_at TEXT NULL)');
        $this->database->table('sucursales')->insert(['id' => 7, 'empresa_id' => 5, 'codigo' => 'CENTRAL', 'estado' => 1, 'deleted_at' => null]);
        $this->database->table('tipos_equipo')->insert(['id' => 3, 'nombre' => 'Camion', 'controla_km' => 1, 'controla_horas' => 0, 'activo' => 1]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testNumericActiveBranchIsResolvedAndAllowsImport(): void
    {
        $reference = new CodeIgniterImportReferenceGateway($this->database);
        self::assertSame(7, $reference->activeBranchByCode(5, 'CENTRAL')['id'] ?? null);

        $gateway = new CodeIgniterAssetImportGateway($this->database);
        $id = $gateway->import(new AssetImportData(
            5, 7, 3, 'CAM-CSV-1', null, null, null, null, null, null,
            '2026-08-08', null, 9, 40,
        ));

        self::assertGreaterThan(0, $id);
        self::assertSame(1, $this->database->table('equipos')->where('empresa_id', 5)->where('codigo', 'CAM-CSV-1')->countAllResults());
    }
}
