<?php

declare(strict_types=1);

use App\Application\Importations\ExpirationImportData;
use App\Infrastructure\Expirations\CodeIgniterExpirationImportGateway;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterExpirationImportGatewayTest extends TestCase
{
    private mixed $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            self::markTestSkipped('La regresión requiere sqlite3.');
        }
        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE tipos_vencimiento (id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER, nombre TEXT, aplica_a TEXT, dias_aviso_previo INTEGER, requiere_documento INTEGER, activo INTEGER, created_by INTEGER NULL, updated_by INTEGER NULL, created_at TEXT NULL, updated_at TEXT NULL, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, estado TEXT, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE vencimientos (id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER, sucursal_id INTEGER, tipo_vencimiento_id INTEGER, sujeto_tipo TEXT, equipo_id INTEGER, fecha_emision TEXT NULL, fecha_vencimiento TEXT, numero_documento TEXT NULL, observaciones TEXT NULL, origen TEXT, importacion_id INTEGER NULL, activo INTEGER, created_by INTEGER NULL, updated_by INTEGER NULL, created_at TEXT NULL, updated_at TEXT NULL, deleted_at TEXT NULL)');
        $this->database->table('equipos')->insert(['id' => 10, 'empresa_id' => 5, 'sucursal_id' => 7, 'estado' => 'ACTIVO', 'deleted_at' => null]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testImportCreatesCatalogAndIsIdempotentWithinTenant(): void
    {
        $data = new ExpirationImportData(5, 7, 10, 'POLIZA', '2027-08-22', null, null, null, 9, 40);
        $gateway = new CodeIgniterExpirationImportGateway($this->database);

        $id = $gateway->import($data);

        self::assertGreaterThan(0, $id);
        self::assertTrue($gateway->isDuplicate($data));
        self::assertSame(1, $this->database->table('tipos_vencimiento')->where('empresa_id', 5)->countAllResults());
        self::assertSame(1, $this->database->table('vencimientos')->where('empresa_id', 5)->countAllResults());
        self::assertSame($id, $gateway->import($data));
        self::assertSame(1, $this->database->table('vencimientos')->where('empresa_id', 5)->countAllResults());
    }

    public function testImportRejectsEquipmentOutsideCompanyOrBranch(): void
    {
        $gateway = new CodeIgniterExpirationImportGateway($this->database);
        $this->expectException(DomainException::class);

        $gateway->import(new ExpirationImportData(6, 7, 10, 'VTV', '2027-06-06', null, null, null, 9, 40));
    }
}
