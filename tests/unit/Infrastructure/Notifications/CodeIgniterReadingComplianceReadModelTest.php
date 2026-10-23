<?php

declare(strict_types=1);

use App\Infrastructure\Notifications\CodeIgniterReadingComplianceReadModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * Lecturas vencidas contra un motor SQL real.
 *
 * Lo que no se puede comprobar con dobles: que el filtro por tipo de equipo
 * que controla kilometraje se aplique en la consulta y no despues, en memoria.
 *
 * El caso que importa: un semirremolque NO tiene lectura de kilometraje que
 * informar. Si se contara, la gerencia recibiria un informe lleno de equipos
 * que jamas van a subir una lectura, porque no controlan km.
 */
final class CodeIgniterReadingComplianceReadModelTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La lectura vencida requiere sqlite3.');
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
     * Con "ahora" en 2026-10-23 y un corte de 7 dias (2026-10-16):
     *
     *  - CAM-01: controla km, ultima lectura hace 11 dias. Entra.
     *  - SEM-02: NO controla km y no tiene lecturas. NO entra: no tiene km que informar.
     *  - CAM-03: controla km, ultima lectura hace 3 dias. NO entra.
     *  - CAM-04: controla km, sin ninguna lectura. Entra: es el peor caso.
     *  - CAM-05: tipo inactivo. NO entra.
     *  - CAM-06: sucursal inactiva. NO entra.
     *  - OTRO-01: otra empresa. NO entra.
     */
    private function seedData(): void
    {
        $this->database->table('tipos_equipo')->insertBatch([
            ['id' => 1, 'nombre' => 'Camión', 'controla_km' => 1, 'controla_horas' => 0, 'activo' => 1],
            ['id' => 2, 'nombre' => 'Semirremolque', 'controla_km' => 0, 'controla_horas' => 0, 'activo' => 1],
            ['id' => 3, 'nombre' => 'Inactivo', 'controla_km' => 1, 'controla_horas' => 0, 'activo' => 0],
        ]);
        $this->database->table('sucursales')->insertBatch([
            ['id' => 7, 'empresa_id' => 5, 'nombre' => 'Central', 'estado' => 1, 'deleted_at' => null],
            ['id' => 8, 'empresa_id' => 5, 'nombre' => 'Norte', 'estado' => 0, 'deleted_at' => null],
            ['id' => 9, 'empresa_id' => 99, 'nombre' => 'Otra empresa', 'estado' => 1, 'deleted_at' => null],
        ]);
        $this->database->table('equipos')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 1, 'codigo' => 'CAM-01', 'patente' => 'AB499OK', 'chasis' => 'CH-10', 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 11, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 2, 'codigo' => 'SEM-02', 'patente' => 'SEM0200', 'chasis' => 'CH-11', 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 12, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 1, 'codigo' => 'CAM-03', 'patente' => 'AF117ZK', 'chasis' => 'CH-12', 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 13, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 1, 'codigo' => 'CAM-04', 'patente' => 'UU111VV', 'chasis' => 'CH-13', 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 14, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 3, 'codigo' => 'CAM-05', 'patente' => 'TT555YY', 'chasis' => 'CH-14', 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 15, 'empresa_id' => 5, 'sucursal_id' => 8, 'tipo_equipo_id' => 1, 'codigo' => 'CAM-06', 'patente' => 'TT666ZZ', 'chasis' => 'CH-15', 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 90, 'empresa_id' => 99, 'sucursal_id' => 9, 'tipo_equipo_id' => 1, 'codigo' => 'OTRO-01', 'patente' => 'XX000XX', 'chasis' => 'CH-90', 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
        ]);
        $this->database->table('empleados')->insertBatch([
            ['id' => 55, 'empresa_id' => 5, 'nombre' => 'ISMAEL', 'apellido' => 'HORACIO DUBE', 'telefono' => null, 'activo' => 1, 'deleted_at' => null],
        ]);
        $this->database->table('employee_equipment_assignments')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 10, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 2, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 13, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
        ]);
        $this->database->table('lecturas_equipo')->insertBatch([
            ['id' => 100, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 10, 'fecha_lectura' => '2026-10-12 09:58:00', 'kilometraje' => 1002337, 'horometro' => null, 'origen' => 'MANUAL', 'usuario_id' => 55, 'anulada' => 0],
            ['id' => 101, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 10, 'fecha_lectura' => '2026-10-20 09:58:00', 'kilometraje' => 9999999, 'horometro' => null, 'origen' => 'MANUAL', 'usuario_id' => 55, 'anulada' => 1],
            ['id' => 102, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 12, 'fecha_lectura' => '2026-10-20 08:00:00', 'kilometraje' => 492320, 'horometro' => null, 'origen' => 'MANUAL', 'usuario_id' => 55, 'anulada' => 0],
        ]);
    }

    public function testOnlyEquipmentThatControlsKilometresIsReported(): void
    {
        $items = $this->readings();

        $codes = array_map(static fn (array $item): string => $item['code'], $items);
        sort($codes);

        self::assertSame(['CAM-01', 'CAM-04'], $codes, 'El semirremolque no controla km: no tiene lectura que informar.');
    }

    public function testEquipmentWithoutAnyReadingIsReportedFirst(): void
    {
        $items = $this->readings();

        self::assertSame('CAM-04', $items[0]['code'], 'El equipo que nunca registró lectura es el peor caso.');
        self::assertNull($items[0]['lastReadingAt']);
        self::assertNull($items[0]['lastKm']);
    }

    public function testTheLastReadingComesWithItsOwnKilometre(): void
    {
        $items = $this->readings();
        $cam01 = $items[1];

        self::assertSame('CAM-01', $cam01['code']);
        self::assertSame('2026-10-12 09:58:00', $cam01['lastReadingAt']);
        self::assertSame(1002337, $cam01['lastKm'], 'El kilometraje viene de la MISMA fila que la fecha.');
    }

    public function testTheCurrentDriverIsReported(): void
    {
        $items = $this->readings();

        self::assertSame('ISMAEL HORACIO DUBE', $items[1]['driver']);
        self::assertSame('ISMAEL HORACIO DUBE', $items[0]['driver']);
    }

    public function testEquipmentOfAnotherCompanyIsNeverReported(): void
    {
        foreach ($this->readings() as $item) {
            self::assertNotSame('OTRO-01', $item['code']);
        }
    }

    public function testEquipmentWithoutKilometreControlIsNeverQueried(): void
    {
        $cutoff = new DateTimeImmutable('2026-10-16 00:00:00', new DateTimeZone('UTC'));
        $readModel = new CodeIgniterReadingComplianceReadModel($this->database);

        $items = $readModel->staleReadings(5, $cutoff, 25);

        self::assertNotContains('SEM-02', array_map(static fn (array $item): string => $item['code'], $items));
    }

    /**
     * @return list<array{equipmentId:int,code:string,plate:string,driver:string,lastReadingAt:?string,lastKm:?int}>
     */
    private function readings(): array
    {
        $readModel = new CodeIgniterReadingComplianceReadModel($this->database);
        $cutoff = new DateTimeImmutable('2026-10-16 00:00:00', new DateTimeZone('UTC'));

        return $readModel->staleReadings(5, $cutoff, 25);
    }
}
