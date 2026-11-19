<?php

declare(strict_types=1);

use App\Application\Reports\ReportScope;
use App\Infrastructure\Reports\CodeIgniterTelemetryReliabilityDataReader;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterTelemetryReliabilityDataReaderTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión del informe requiere sqlite3.');
        }
        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, estado INTEGER, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, codigo TEXT, patente TEXT NULL, estado TEXT, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE equipo_telemetria (id INTEGER PRIMARY KEY, empresa_id INTEGER, equipo_id INTEGER, activo INTEGER)');
        $this->database->query('CREATE TABLE integraciones_telemetria (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, proveedor TEXT)');
        $this->database->query('CREATE TABLE lecturas_equipo (id INTEGER PRIMARY KEY, empresa_id INTEGER, equipo_id INTEGER, fecha_lectura TEXT, kilometraje INTEGER NULL, origen TEXT, anulada INTEGER DEFAULT 0)');
        $this->database->query('CREATE TABLE telematia_muestras (id INTEGER PRIMARY KEY, empresa_id INTEGER, integracion_id INTEGER, equipo_id INTEGER, proveedor TEXT, observada_en TEXT, kilometraje INTEGER NULL)');

        $this->database->table('sucursales')->insertBatch([
            ['id' => 9, 'empresa_id' => 5, 'nombre' => 'Central', 'estado' => 1, 'deleted_at' => null],
            ['id' => 8, 'empresa_id' => 5, 'nombre' => 'Sur', 'estado' => 1, 'deleted_at' => null],
        ]);
        $this->database->table('equipos')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'sucursal_id' => 9, 'codigo' => 'CAM-01', 'patente' => 'AB123CD', 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 11, 'empresa_id' => 5, 'sucursal_id' => 8, 'codigo' => 'CAM-02', 'patente' => 'EF456GH', 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 12, 'empresa_id' => 6, 'sucursal_id' => 4, 'codigo' => 'OTRO-01', 'patente' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
        ]);
        $this->database->table('equipo_telemetria')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'equipo_id' => 10, 'activo' => 1],
            ['id' => 2, 'empresa_id' => 5, 'equipo_id' => 11, 'activo' => 1],
            ['id' => 3, 'empresa_id' => 6, 'equipo_id' => 12, 'activo' => 1],
        ]);
        $this->database->table('integraciones_telemetria')->insert([
            'id' => 31, 'empresa_id' => 5, 'nombre' => 'Obersat', 'proveedor' => 'wialon',
        ]);
        $this->database->table('lecturas_equipo')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'equipo_id' => 10, 'fecha_lectura' => '2026-08-01 08:00:00', 'kilometraje' => 1000, 'origen' => 'MANUAL', 'anulada' => 0],
            ['id' => 2, 'empresa_id' => 5, 'equipo_id' => 10, 'fecha_lectura' => '2026-08-02 08:00:00', 'kilometraje' => 1500, 'origen' => 'ORDEN_TRABAJO', 'anulada' => 0],
            ['id' => 3, 'empresa_id' => 5, 'equipo_id' => 10, 'fecha_lectura' => '2026-08-03 08:00:00', 'kilometraje' => 2000, 'origen' => 'CARGA_RAPIDA', 'anulada' => 1],
            ['id' => 4, 'empresa_id' => 5, 'equipo_id' => 11, 'fecha_lectura' => '2026-08-01 08:00:00', 'kilometraje' => 500, 'origen' => 'QR_ANONIMO', 'anulada' => 0],
        ]);
        $this->database->table('telematia_muestras')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'integracion_id' => 31, 'equipo_id' => 10, 'proveedor' => 'wialon', 'observada_en' => '2026-08-01 08:05:00', 'kilometraje' => 51000],
            ['id' => 2, 'empresa_id' => 5, 'integracion_id' => 31, 'equipo_id' => 11, 'proveedor' => 'wialon', 'observada_en' => '2026-08-01 08:05:00', 'kilometraje' => 5000],
            ['id' => 3, 'empresa_id' => 6, 'integracion_id' => 31, 'equipo_id' => 12, 'proveedor' => 'wialon', 'observada_en' => '2026-08-01 08:05:00', 'kilometraje' => 9000],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testReadsOnlyDriverOriginReadingsWithinTheAuthorizedBranch(): void
    {
        $reader = new CodeIgniterTelemetryReliabilityDataReader($this->database);
        $data = $reader->fetch(new ReportScope(
            5, [9], 9, new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-08'), 1, 10,
        ));

        self::assertSame([10], array_column($data['equipment'], 'id'));
        self::assertSame([10], array_column($data['readings'], 'equipmentId'));
        self::assertCount(1, $data['readings']);
        self::assertSame([10], array_column($data['samples'], 'equipmentId'));
        self::assertSame('Obersat', $data['samples'][0]['integrationName']);
        self::assertSame([['id' => 9, 'nombre' => 'Central']], $data['branches']);
    }

    public function testAllAuthorizedBranchesIncludeEveryCompanyEquipmentButNotOtherCompanies(): void
    {
        $reader = new CodeIgniterTelemetryReliabilityDataReader($this->database);
        $data = $reader->fetch(new ReportScope(
            5, null, null, new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-08'), 1, 10,
        ));

        self::assertSame([10, 11], array_column($data['equipment'], 'id'));
        self::assertCount(2, $data['readings']);
        self::assertCount(2, $data['samples']);
    }
}
