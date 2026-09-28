<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\ReadingControl\ListReadingControl;
use App\Application\ReadingControl\ReadingControlQuery;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReadingControl\ReadingControlClockFake;

/**
 * Integración del caso de uso contra un motor SQL real.
 *
 * Cubre lo que no se puede comprobar con dobles: que la última lectura y su
 * kilometraje provengan de la MISMA fila, que los filtros se apliquen antes de
 * paginar y que el aislamiento por empresa se respete en la consulta.
 *
 * Las tablas se replican con las columnas REALES declaradas por las
 * migraciones: `tipos_equipo` no tiene `deleted_at`, y `lecturas_equipo` no
 * tiene `deleted_at` ni `created_by`.
 */
final class CodeIgniterListReadingControlTest extends TestCase
{
    private const NOW = '2026-09-28 10:00:00';

    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La integración del control de lecturas requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database'    => ':memory:',
            'DBDriver'    => 'SQLite3',
            'DBPrefix'    => '',
            'DBDebug'     => true,
            'foreignKeys' => true,
        ], false);

        $this->createSchema();
        $this->seedData();
    }

    private function createSchema(): void
    {
        $this->database->query(
            'CREATE TABLE tipos_equipo (id INTEGER PRIMARY KEY, nombre TEXT, controla_km INTEGER, controla_horas INTEGER, activo INTEGER)',
        );
        $this->database->query(
            'CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, estado INTEGER, deleted_at TEXT NULL)',
        );
        $this->database->query(
            'CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, tipo_equipo_id INTEGER,'
            . ' codigo TEXT, patente TEXT NULL, chasis TEXT NULL, km_actual INTEGER NULL, estado TEXT, deleted_at TEXT NULL)',
        );
        $this->database->query(
            'CREATE TABLE empleados (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, apellido TEXT, telefono TEXT NULL, activo INTEGER, deleted_at TEXT NULL)',
        );
        $this->database->query(
            'CREATE TABLE employee_equipment_assignments (id INTEGER PRIMARY KEY, empresa_id INTEGER, empleado_id INTEGER,'
            . ' equipo_id INTEGER, rol TEXT, fecha_desde TEXT, fecha_hasta TEXT NULL)',
        );
        $this->database->query(
            'CREATE TABLE lecturas_equipo (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, equipo_id INTEGER,'
            . ' fecha_lectura TEXT, kilometraje INTEGER NULL, horometro REAL NULL, origen TEXT, usuario_id INTEGER, anulada INTEGER)',
        );
    }

    /**
     * Datos de la escena, con "ahora" en 2026-09-28 10:00:
     *
     *  - CAM-01: cargó hoy (0 días). Tiene además una lectura anterior y una anulada.
     *  - CAM-02: última lectura hace 27 días, sin chofer asignado.
     *  - CAM-03: última lectura hace 5 días. Entra en gt_3 pero no en gt_7.
     *  - CAM-04: sin ninguna lectura. Es el peor caso.
     *  - CAM-05: tipo de equipo inactivo, no debe aparecer.
     *  - OTRO-01: pertenece a otra empresa, no debe aparecer para empresa 5.
     */
    private function seedData(): void
    {
        $this->database->table('tipos_equipo')->insertBatch([
            ['id' => 1, 'nombre' => 'Camión', 'controla_km' => 1, 'controla_horas' => 0, 'activo' => 1],
            ['id' => 2, 'nombre' => 'Tractor', 'controla_km' => 1, 'controla_horas' => 1, 'activo' => 1],
            ['id' => 3, 'nombre' => 'Inactivo', 'controla_km' => 1, 'controla_horas' => 0, 'activo' => 0],
        ]);
        $this->database->table('sucursales')->insertBatch([
            ['id' => 7, 'empresa_id' => 5, 'nombre' => 'Central', 'estado' => 1, 'deleted_at' => null],
            ['id' => 8, 'empresa_id' => 5, 'nombre' => 'Norte', 'estado' => 1, 'deleted_at' => null],
            ['id' => 9, 'empresa_id' => 99, 'nombre' => 'Otra empresa', 'estado' => 1, 'deleted_at' => null],
        ]);
        $this->database->table('equipos')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 1, 'codigo' => 'CAM-01', 'patente' => 'AA123BB', 'chasis' => 'CH-10', 'km_actual' => 999999, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 11, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 1, 'codigo' => 'CAM-02', 'patente' => null, 'chasis' => 'CH-11', 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 12, 'empresa_id' => 5, 'sucursal_id' => 8, 'tipo_equipo_id' => 2, 'codigo' => 'CAM-03', 'patente' => 'ZZ999XX', 'chasis' => 'CH-12', 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 13, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 1, 'codigo' => 'CAM-04', 'patente' => 'UU111VV', 'chasis' => 'CH-13', 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 14, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 3, 'codigo' => 'CAM-05', 'patente' => 'TT555YY', 'chasis' => 'CH-14', 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 90, 'empresa_id' => 99, 'sucursal_id' => 9, 'tipo_equipo_id' => 1, 'codigo' => 'OTRO-01', 'patente' => 'XX000XX', 'chasis' => 'CH-90', 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
        ]);
        $this->database->table('empleados')->insertBatch([
            ['id' => 55, 'empresa_id' => 5, 'nombre' => 'Juan', 'apellido' => 'Pérez', 'telefono' => '3514449999', 'activo' => 1, 'deleted_at' => null],
            ['id' => 56, 'empresa_id' => 5, 'nombre' => 'Ana', 'apellido' => 'García', 'telefono' => null, 'activo' => 1, 'deleted_at' => null],
        ]);
        $this->database->table('employee_equipment_assignments')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 10, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 2, 'empresa_id' => 5, 'empleado_id' => 56, 'equipo_id' => 12, 'rol' => 'CHOFER', 'fecha_desde' => '2026-02-01', 'fecha_hasta' => null],
        ]);
        $this->database->table('lecturas_equipo')->insertBatch([
            ['id' => 100, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 10, 'fecha_lectura' => '2026-09-20 08:00:00', 'kilometraje' => 180000, 'horometro' => null, 'origen' => 'MANUAL', 'usuario_id' => 55, 'anulada' => 0],
            ['id' => 101, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 10, 'fecha_lectura' => '2026-09-28 08:30:00', 'kilometraje' => 185000, 'horometro' => null, 'origen' => 'MANUAL', 'usuario_id' => 55, 'anulada' => 0],
            ['id' => 102, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 10, 'fecha_lectura' => '2026-09-30 08:30:00', 'kilometraje' => 999999, 'horometro' => null, 'origen' => 'MANUAL', 'usuario_id' => 55, 'anulada' => 1],
            ['id' => 103, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 11, 'fecha_lectura' => '2026-09-01 10:00:00', 'kilometraje' => 120000, 'horometro' => null, 'origen' => 'MANUAL', 'usuario_id' => 55, 'anulada' => 0],
            ['id' => 104, 'empresa_id' => 5, 'sucursal_id' => 8, 'equipo_id' => 12, 'fecha_lectura' => '2026-09-23 08:00:00', 'kilometraje' => 90000, 'horometro' => null, 'origen' => 'MANUAL', 'usuario_id' => 56, 'anulada' => 0],
        ]);
    }

    private function useCase(): ListReadingControl
    {
        return new ListReadingControl(
            $this->database,
            new ReadingControlClockFake(new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'))),
        );
    }

    private function actor(int $companyId = 5): ActorContext
    {
        return new ActorContext(4, $companyId, false, true, ['Administrador'], ['equipos.ver'], []);
    }

    /**
     * @param array{items: list<object>, total: int, pagination: array<string, int>} $result
     *
     * @return list<string>
     */
    private function codesOf(array $result): array
    {
        return array_map(static fn ($row): string => $row->equipmentCode, $result['items']);
    }

    public function testReturnsOneRowPerEquipmentAndExcludesInactiveTypes(): void
    {
        $result = $this->useCase()->execute($this->actor(), new ReadingControlQuery());
        $codes = $this->codesOf($result);
        sort($codes);

        // CAM-05 usa un tipo inactivo y OTRO-01 pertenece a otra empresa.
        self::assertSame(['CAM-01', 'CAM-02', 'CAM-03', 'CAM-04'], $codes);
        self::assertSame(4, $result['total']);
    }

    public function testLastKilometersBelongToTheMostRecentReading(): void
    {
        $result = $this->useCase()->execute($this->actor(), new ReadingControlQuery(query: 'CAM-01'));
        $row = $result['items'][0];

        self::assertSame(185000, $row->lastKm, 'El km debe ser el de la lectura más reciente, no el de la anterior.');
        self::assertStringStartsWith('2026-09-28 08:30:00', (string) $row->lastReadingAt);
        self::assertSame(0, $row->daysSinceLastReading);
    }

    public function testAnnulatedReadingsAreIgnored(): void
    {
        $result = $this->useCase()->execute($this->actor(), new ReadingControlQuery(query: 'CAM-01'));
        $row = $result['items'][0];

        self::assertNotSame(999999, $row->lastKm, 'Una lectura anulada no puede ser la última lectura.');
        self::assertStringStartsWith('2026-09-28', (string) $row->lastReadingAt);
    }

    public function testEquipmentWithoutReadingsIsReportedAsSuch(): void
    {
        $result = $this->useCase()->execute($this->actor(), new ReadingControlQuery(query: 'CAM-04'));
        $row = $result['items'][0];

        self::assertFalse($row->hasReading());
        self::assertNull($row->lastKm);
        self::assertNull($row->lastReadingAt);
        self::assertNull($row->daysSinceLastReading);
    }

    public function testReportsDriverAndPhoneWhenAvailable(): void
    {
        $row = $this->useCase()->execute($this->actor(), new ReadingControlQuery(query: 'CAM-01'))['items'][0];

        self::assertTrue($row->hasDriver());
        self::assertSame('Juan Pérez', $row->driverName);
        self::assertSame('3514449999', $row->driverPhone);
    }

    public function testEquipmentWithoutDriverFallsBackToPlaceholder(): void
    {
        $row = $this->useCase()->execute($this->actor(), new ReadingControlQuery(query: 'CAM-02'))['items'][0];

        self::assertFalse($row->hasDriver());
        self::assertSame('(sin chofer)', $row->driverName);
    }

    public function testTodayFilterSelectsOnlyReadingsFromTheCurrentDay(): void
    {
        $result = $this->useCase()->execute($this->actor(), new ReadingControlQuery(filter: 'today'));

        self::assertSame(['CAM-01'], $this->codesOf($result));
        self::assertSame(1, $result['total']);
    }

    public function testNotTodayFilterIncludesEquipmentWithoutReadings(): void
    {
        $result = $this->useCase()->execute($this->actor(), new ReadingControlQuery(filter: 'not_today'));
        $codes = $this->codesOf($result);
        sort($codes);

        self::assertSame(['CAM-02', 'CAM-03', 'CAM-04'], $codes);
        self::assertSame(3, $result['total']);
    }

    public function testGreaterThanThreeDaysFilterExcludesRecentReadings(): void
    {
        $result = $this->useCase()->execute($this->actor(), new ReadingControlQuery(filter: 'gt_3'));
        $codes = $this->codesOf($result);
        sort($codes);

        // CAM-03 (5 días), CAM-02 (27 días) y CAM-04 (sin lectura).
        self::assertSame(['CAM-02', 'CAM-03', 'CAM-04'], $codes);
        self::assertSame(3, $result['total']);
    }

    public function testGreaterThanSevenDaysFilterIsStricterThanThree(): void
    {
        $result = $this->useCase()->execute($this->actor(), new ReadingControlQuery(filter: 'gt_7'));
        $codes = $this->codesOf($result);
        sort($codes);

        // CAM-03 tiene 5 días: entra en gt_3 pero no en gt_7.
        self::assertSame(['CAM-02', 'CAM-04'], $codes);
        self::assertSame(2, $result['total']);
    }

    public function testPaginationCountsTheFilteredSetNotThePartialPage(): void
    {
        $result = $this->useCase()->execute($this->actor(), new ReadingControlQuery(filter: 'gt_3', perPage: 1));

        self::assertCount(1, $result['items']);
        self::assertSame(3, $result['total'], 'El total debe contar el conjunto filtrado completo.');
        self::assertSame(3, $result['pagination']['totalPages']);
    }

    public function testPaginationSplitsTheSetAcrossPagesWithoutRepeating(): void
    {
        $first = $this->useCase()->execute($this->actor(), new ReadingControlQuery(perPage: 2, page: 1));
        $second = $this->useCase()->execute($this->actor(), new ReadingControlQuery(perPage: 2, page: 2));

        self::assertCount(2, $first['items']);
        self::assertCount(2, $second['items']);
        self::assertSame(4, $first['total']);
        self::assertSame(2, $first['pagination']['totalPages']);

        $ids = array_merge(
            array_map(static fn ($row): int => $row->equipmentId, $first['items']),
            array_map(static fn ($row): int => $row->equipmentId, $second['items']),
        );
        self::assertSame($ids, array_unique($ids), 'Las páginas no deben repetir equipos.');
    }

    public function testDefaultOrderPutsTheMostStaleRecordsFirst(): void
    {
        $result = $this->useCase()->execute($this->actor(), new ReadingControlQuery());

        // Sin lectura primero, luego del más antiguo al más reciente.
        self::assertSame(['CAM-04', 'CAM-02', 'CAM-03', 'CAM-01'], $this->codesOf($result));
    }

    public function testResultsAreScopedToTheCurrentCompany(): void
    {
        $result = $this->useCase()->execute($this->actor(99), new ReadingControlQuery());

        self::assertSame(['OTRO-01'], $this->codesOf($result));
    }

    public function testBranchAndTypeFiltersAreApplied(): void
    {
        $byBranch = $this->useCase()->execute($this->actor(), new ReadingControlQuery(branchId: 8));
        self::assertSame(['CAM-03'], $this->codesOf($byBranch));

        $byType = $this->useCase()->execute($this->actor(), new ReadingControlQuery(typeId: 2));
        self::assertSame(['CAM-03'], $this->codesOf($byType));
    }

    public function testSearchMatchesPlateAndDriver(): void
    {
        $byPlate = $this->useCase()->execute($this->actor(), new ReadingControlQuery(query: 'ZZ999XX'));
        self::assertSame(['CAM-03'], $this->codesOf($byPlate));

        $byDriver = $this->useCase()->execute($this->actor(), new ReadingControlQuery(query: 'garcía'));
        self::assertSame(['CAM-03'], $this->codesOf($byDriver));
    }

    public function testRequestWithoutAnyFilterDoesNotFail(): void
    {
        $result = $this->useCase()->execute($this->actor(), ReadingControlQuery::fromRequest([]));

        self::assertSame(4, $result['total']);
    }

    public function testSummaryCountsStaleAndMissingReadings(): void
    {
        $summary = $this->useCase()->execute($this->actor(), new ReadingControlQuery())['summary'];

        self::assertSame(4, $summary['total']);
        self::assertSame(1, $summary['sinLectura']);
        self::assertSame(1, $summary['antiguos']);
    }
}
