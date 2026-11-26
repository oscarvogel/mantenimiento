<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Platform;

use App\Infrastructure\Platform\CodeIgniterFleetAvailabilityReadModel;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class CodeIgniterFleetAvailabilityReadModelTest extends CIUnitTestCase
{
    private $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La disponibilidad de flota requiere sqlite3.');
        }

        parent::setUp();
        $this->database = Database::connect('tests');
        $this->database->setPrefix('');
        $this->dropTables();
        // Tipos como en las migraciones: equipos.estado VARCHAR 'ACTIVO', equipo_fuera_servicio TINYINT, sucursales.estado TINYINT.
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre VARCHAR(255), estado TINYINT DEFAULT 1, deleted_at DATETIME NULL)');
        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, codigo VARCHAR(50), patente VARCHAR(20) NULL, estado VARCHAR(20), deleted_at DATETIME NULL)');
        $this->database->query('CREATE TABLE tipos_servicio (id INTEGER PRIMARY KEY, nombre VARCHAR(120))');
        $this->database->query('CREATE TABLE ordenes_trabajo (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, equipo_id INTEGER, numero VARCHAR(20), estado VARCHAR(30), fecha_apertura DATETIME, equipo_fuera_servicio TINYINT DEFAULT 0, inicio_detencion DATETIME NULL, fin_detencion DATETIME NULL, diagnostico TEXT NULL, tipo_servicio_id INTEGER NULL)');

        $this->database->table('sucursales')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'nombre' => 'Casa Central', 'estado' => 1, 'deleted_at' => null],
            ['id' => 11, 'empresa_id' => 5, 'nombre' => 'Rosario', 'estado' => 1, 'deleted_at' => null],
            ['id' => 12, 'empresa_id' => 5, 'nombre' => 'Cerrada', 'estado' => 0, 'deleted_at' => null],
            ['id' => 20, 'empresa_id' => 6, 'nombre' => 'Otra empresa', 'estado' => 1, 'deleted_at' => null],
        ]);
        $this->database->table('equipos')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'sucursal_id' => 10, 'codigo' => 'TR-12', 'patente' => 'AB123CD', 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 2, 'empresa_id' => 5, 'sucursal_id' => 10, 'codigo' => 'AC-07', 'patente' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 3, 'empresa_id' => 5, 'sucursal_id' => 11, 'codigo' => 'SR-02', 'patente' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 4, 'empresa_id' => 5, 'sucursal_id' => 10, 'codigo' => 'BAJA-1', 'patente' => null, 'estado' => 'BAJA', 'deleted_at' => null],
            ['id' => 5, 'empresa_id' => 5, 'sucursal_id' => 10, 'codigo' => 'BORRADO', 'patente' => null, 'estado' => 'ACTIVO', 'deleted_at' => '2026-11-01 00:00:00'],
            ['id' => 6, 'empresa_id' => 6, 'sucursal_id' => 20, 'codigo' => 'AJENO', 'patente' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
        ]);
        $this->database->table('tipos_servicio')->insert(['id' => 1, 'nombre' => 'Service 20.000 km']);
        $order = static fn (int $id, int $equipmentId, string $status, array $extra = []): array => [
            'id' => $id, 'empresa_id' => 5, 'sucursal_id' => 10, 'equipo_id' => $equipmentId, 'numero' => 'OT-' . $id,
            'estado' => $status, 'fecha_apertura' => '2026-11-0' . $id . ' 08:00:00', 'equipo_fuera_servicio' => 0,
            'inicio_detencion' => null, 'fin_detencion' => null, 'diagnostico' => null, 'tipo_servicio_id' => null, ...$extra,
        ];
        $this->database->table('ordenes_trabajo')->insertBatch([
            $order(1, 1, 'EN_PROCESO', ['equipo_fuera_servicio' => 1, 'diagnostico' => 'Motor']),
            $order(2, 1, 'FINALIZADA', ['inicio_detencion' => '2026-10-01 00:00:00']),
            $order(3, 2, 'BORRADOR', ['tipo_servicio_id' => 1]),
            $order(4, 3, 'CANCELADA'),
            ['id' => 9, 'empresa_id' => 6, 'sucursal_id' => 20, 'equipo_id' => 6, 'numero' => 'OT-9', 'estado' => 'EN_PROCESO', 'fecha_apertura' => '2026-11-09 08:00:00', 'equipo_fuera_servicio' => 1, 'inicio_detencion' => null, 'fin_detencion' => null, 'diagnostico' => null, 'tipo_servicio_id' => null],
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        $this->database->resetDataCache();
        parent::tearDown();
    }

    public function testReadsActiveEquipmentWithTheirOpenOrdersInsideTheCompany(): void
    {
        $rows = (new CodeIgniterFleetAvailabilityReadModel($this->database))->rows(5, null, null);

        self::assertSame([1, 2, 3], array_values(array_unique(array_column($rows, 'equipmentId'))));
        $byOrder = array_column($rows, null, 'orderNumber');
        self::assertArrayNotHasKey('OT-2', $byOrder, 'Una OT finalizada no es abierta');
        self::assertArrayNotHasKey('OT-4', $byOrder, 'Una OT cancelada no es abierta');
        self::assertTrue($byOrder['OT-1']['outOfService']);
        self::assertSame('Motor', $byOrder['OT-1']['reason']);
        self::assertSame('TR-12', $byOrder['OT-1']['code']);
        self::assertSame('AB123CD', $byOrder['OT-1']['plate']);
        self::assertSame('Casa Central', $byOrder['OT-1']['branchName']);
        self::assertSame('BORRADOR', $byOrder['OT-3']['orderStatus'], 'Un borrador cuenta como abierta');
        self::assertSame('Service 20.000 km', $byOrder['OT-3']['reason'], 'Sin diagnóstico, el motivo es el tipo de servicio');
        self::assertFalse($byOrder['OT-3']['outOfService']);

        $withoutOrder = array_values(array_filter($rows, static fn (array $row): bool => $row['equipmentId'] === 3));
        self::assertCount(1, $withoutOrder);
        self::assertNull($withoutOrder[0]['orderId']);
    }

    public function testRestrictsToTheActorsBranchesAndTheRequestedBranch(): void
    {
        $model = new CodeIgniterFleetAvailabilityReadModel($this->database);

        self::assertSame([1, 2], array_values(array_unique(array_column($model->rows(5, [10], null), 'equipmentId'))));
        self::assertSame([3], array_values(array_unique(array_column($model->rows(5, null, 11), 'equipmentId'))));
        self::assertSame([], $model->rows(5, [10], 11));
        self::assertSame([], $model->rows(5, [], null));
    }

    public function testListsOnlyActiveBranchesTheActorCanSee(): void
    {
        $model = new CodeIgniterFleetAvailabilityReadModel($this->database);

        self::assertSame([['id' => 10, 'name' => 'Casa Central'], ['id' => 11, 'name' => 'Rosario']], $model->branches(5, null));
        self::assertSame([['id' => 11, 'name' => 'Rosario']], $model->branches(5, [11]));
    }

    private function dropTables(): void
    {
        foreach (['ordenes_trabajo', 'tipos_servicio', 'equipos', 'sucursales'] as $table) {
            $this->database->query('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
