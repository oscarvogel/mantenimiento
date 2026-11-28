<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\WorkRequests;

use App\Infrastructure\WorkRequests\CodeIgniterWorkRequestRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class CodeIgniterWorkRequestRepositoryTest extends CIUnitTestCase
{
    private $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('El repositorio de solicitudes requiere sqlite3.');
        }

        parent::setUp();
        $this->database = Database::connect('tests');
        $this->database->setPrefix('');
        $this->database->initialize();
        $sqlite = $this->database->getConnection();
        if (! $sqlite instanceof \SQLite3) {
            $this->markTestSkipped('La conexión de prueba no expone el handle SQLite3.');
        }
        // El listado ordena con FIELD() de MySQL.
        $sqlite->createFunction('FIELD', static fn (?string $value, string ...$list): int => (int) array_search($value, $list, true) + 1);
        $this->dropTables();
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre VARCHAR(255))');
        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo VARCHAR(50), patente VARCHAR(20) NULL)');
        $this->database->query('CREATE TABLE usuarios (id INTEGER PRIMARY KEY, nombre VARCHAR(255))');
        $this->database->query('CREATE TABLE solicitudes_mantenimiento (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, equipo_id INTEGER, reportado_por INTEGER, fecha_reporte DATETIME, descripcion TEXT, estado VARCHAR(20), prioridad VARCHAR(20) DEFAULT \'MEDIA\', motivo_resolucion TEXT NULL, agrupada_en_id INTEGER NULL)');

        $this->database->table('sucursales')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'nombre' => 'Central'],
            ['id' => 11, 'empresa_id' => 5, 'nombre' => 'Sur'],
            ['id' => 20, 'empresa_id' => 9, 'nombre' => 'Ajena'],
        ]);
        $this->database->table('equipos')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'codigo' => 'TR-12', 'patente' => 'AB123CD'],
            ['id' => 2, 'empresa_id' => 9, 'codigo' => 'AJ-01', 'patente' => null],
        ]);
        $this->database->table('usuarios')->insertBatch([['id' => 7, 'nombre' => 'Ana'], ['id' => 8, 'nombre' => 'Beto']]);
        $row = static fn (int $id, int $company, int $branch, int $equipment, string $status, string $description = 'Pierde aire'): array => [
            'id' => $id, 'empresa_id' => $company, 'sucursal_id' => $branch, 'equipo_id' => $equipment, 'reportado_por' => 7,
            'fecha_reporte' => '2026-11-2' . ($id % 9) . ' 08:00:00', 'descripcion' => $description, 'estado' => $status,
        ];
        $this->database->table('solicitudes_mantenimiento')->insertBatch([
            $row(1, 5, 10, 1, 'PENDIENTE'),
            $row(2, 5, 10, 1, 'PENDIENTE', 'Ruido en frenos'),
            $row(3, 5, 10, 1, 'APROBADA'),
            $row(4, 5, 10, 1, 'RECHAZADA'),
            $row(5, 5, 11, 1, 'PENDIENTE'),
            $row(6, 9, 20, 2, 'PENDIENTE'),
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        $this->database->resetDataCache();
        parent::tearDown();
    }

    // Las píldoras de Solicitudes cuentan todo lo que el usuario ve, no solo la página visible.
    public function testCountsByStatusOverTheWholeScopeIgnoringStatusFilterAndPage(): void
    {
        $repository = new CodeIgniterWorkRequestRepository($this->database);

        $result = $repository->listScoped(5, [10], ['status' => 'APROBADA'], 1, 1, null);

        self::assertSame([3], array_column($result['items'], 'id'));
        self::assertEquals(['PENDIENTE' => 2, 'APROBADA' => 1, 'RECHAZADA' => 1], $result['statusCounts']);
    }

    public function testCountsRespectTheSearchAndTheReporterScope(): void
    {
        $repository = new CodeIgniterWorkRequestRepository($this->database);

        self::assertEquals(['PENDIENTE' => 1], $repository->listScoped(5, null, ['q' => 'frenos'], 1, 25, null)['statusCounts']);
        self::assertSame([], $repository->listScoped(5, null, [], 1, 25, 8)['statusCounts']);
        self::assertEquals(['PENDIENTE' => 3, 'APROBADA' => 1, 'RECHAZADA' => 1], $repository->listScoped(5, null, [], 1, 25, null)['statusCounts']);
    }

    public function testCountsOnlyWhatTheListCanShow(): void
    {
        // Solicitud con un reportante que ya no existe: el listado no la muestra, la píldora tampoco la cuenta.
        $this->database->table('solicitudes_mantenimiento')->insert(['id' => 7, 'empresa_id' => 5, 'sucursal_id' => 10, 'equipo_id' => 1, 'reportado_por' => 99, 'fecha_reporte' => '2026-11-20 08:00:00', 'descripcion' => 'Huérfana', 'estado' => 'PENDIENTE']);
        $result = (new CodeIgniterWorkRequestRepository($this->database))->listScoped(5, [10], [], 1, 25, null);

        self::assertSame(4, $result['total']);
        self::assertSame($result['total'], array_sum($result['statusCounts']));
    }

    private function dropTables(): void
    {
        foreach (['solicitudes_mantenimiento', 'usuarios', 'equipos', 'sucursales'] as $table) {
            $this->database->query('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
