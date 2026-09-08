<?php

declare(strict_types=1);

use App\Application\Importations\ExpirationImportData;
use App\Domain\Expirations\ExpirationSubjectType;
use App\Infrastructure\Expirations\CodeIgniterExpirationImportGateway;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterExpirationImportGatewayTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión del gateway requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
            'foreignKeys' => true,
        ], false);

        $this->database->query('CREATE TABLE tipos_vencimiento (id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER NOT NULL, nombre TEXT NOT NULL, aplica_a TEXT NOT NULL, dias_aviso_previo INTEGER NOT NULL DEFAULT 30, requiere_documento INTEGER NOT NULL DEFAULT 0, activo INTEGER NOT NULL DEFAULT 1, created_by INTEGER NULL, updated_by INTEGER NULL, created_at TEXT NULL, updated_at TEXT NULL, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER NOT NULL, sucursal_id INTEGER NOT NULL, estado TEXT NOT NULL, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE empleados (id INTEGER PRIMARY KEY, empresa_id INTEGER NOT NULL, activo INTEGER NOT NULL, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE vencimientos (id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER NOT NULL, sucursal_id INTEGER NULL, tipo_vencimiento_id INTEGER NOT NULL, sujeto_tipo TEXT NOT NULL, equipo_id INTEGER NULL, empleado_id INTEGER NULL, fecha_emision TEXT NULL, fecha_vencimiento TEXT NOT NULL, numero_documento TEXT NULL, observaciones TEXT NULL, origen TEXT NOT NULL, importacion_id INTEGER NULL, activo INTEGER NOT NULL DEFAULT 1, created_by INTEGER NULL, updated_by INTEGER NULL, created_at TEXT NULL, updated_at TEXT NULL, deleted_at TEXT NULL)');

        $this->database->table('equipos')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'sucursal_id' => 7, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 20, 'empresa_id' => 6, 'sucursal_id' => 8, 'estado' => 'ACTIVO', 'deleted_at' => null],
        ]);
        $this->database->table('empleados')->insertBatch([
            ['id' => 44, 'empresa_id' => 5, 'activo' => 1, 'deleted_at' => null],
            ['id' => 55, 'empresa_id' => 6, 'activo' => 1, 'deleted_at' => null],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testImportsEquipmentExpirationAndDetectsDuplicateInsideSameCompany(): void
    {
        $gateway = new CodeIgniterExpirationImportGateway($this->database);
        $data = new ExpirationImportData(5, ExpirationSubjectType::EQUIPMENT, 10, 7, 'VTV', '2027-06-30', null, 'VTV-123', 'Importado desde TSA', 9, 100);

        self::assertFalse($gateway->isDuplicate($data));
        $id = $gateway->import($data);
        self::assertGreaterThan(0, $id);
        self::assertTrue($gateway->isDuplicate($data));

        $row = $this->database->table('vencimientos')->where('id', $id)->get()->getRowArray();
        self::assertSame(5, (int) $row['empresa_id']);
        self::assertSame(10, (int) $row['equipo_id']);
        self::assertNull($row['empleado_id']);
        self::assertSame('EQUIPO', $row['sujeto_tipo']);
        self::assertSame('IMPORTACION', $row['origen']);
    }

    public function testCompanyScopePreventsCrossTenantDuplicateCollision(): void
    {
        $gateway = new CodeIgniterExpirationImportGateway($this->database);
        $gateway->import(new ExpirationImportData(5, ExpirationSubjectType::EQUIPMENT, 10, 7, 'POLIZA', '2027-08-22', null, null, null, 9, 101));

        $otherCompany = new ExpirationImportData(6, ExpirationSubjectType::EQUIPMENT, 20, 8, 'POLIZA', '2027-08-22', null, null, null, 10, 102);
        self::assertFalse($gateway->isDuplicate($otherCompany));
        $id = $gateway->import($otherCompany);
        self::assertGreaterThan(0, $id);
        self::assertSame(1, $this->database->table('vencimientos')->where('empresa_id', 5)->countAllResults());
        self::assertSame(1, $this->database->table('vencimientos')->where('empresa_id', 6)->countAllResults());
    }

    public function testImportsEmployeeExpirationWithoutEquipment(): void
    {
        $gateway = new CodeIgniterExpirationImportGateway($this->database);
        $id = $gateway->import(new ExpirationImportData(5, ExpirationSubjectType::EMPLOYEE, 44, null, 'LICENCIA_CHOFER', '2030-05-09', null, 'LIC-44', null, 9, 103));

        $row = $this->database->table('vencimientos')->where('id', $id)->get()->getRowArray();
        self::assertSame(44, (int) $row['empleado_id']);
        self::assertNull($row['equipo_id']);
        self::assertSame('EMPLEADO', $row['sujeto_tipo']);
    }
}
