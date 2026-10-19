<?php

declare(strict_types=1);

use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use App\Domain\Trips\TripStop;
use App\Infrastructure\Trips\CodeIgniterOperationalTripRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterOperationalTripRepositoryTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de persistencia de viajes requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE viajes_operativos (
            id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER, sucursal_id INTEGER, numero INTEGER,
            estado TEXT, fecha_salida_planificada TEXT, fecha_regreso_planificada TEXT, fecha_salida_real TEXT NULL,
            fecha_regreso_real TEXT NULL, km_salida INTEGER NULL, km_regreso INTEGER NULL, observaciones TEXT NULL,
            motivo_cancelacion TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL, created_by INTEGER NULL, updated_by INTEGER NULL
        )');
        $this->database->query('CREATE TABLE viajes_operativos_numeracion (empresa_id INTEGER PRIMARY KEY, ultimo_numero INTEGER, updated_at TEXT NULL)');
        $this->database->query('CREATE TABLE viaje_operativo_asignaciones (
            id INTEGER PRIMARY KEY AUTOINCREMENT, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER,
            rol TEXT, equipo_id INTEGER NULL, empleado_id INTEGER NULL, desde TEXT, hasta TEXT NULL, motivo TEXT NULL, created_at TEXT NULL
        )');
        $this->database->query('CREATE TABLE viaje_operativo_paradas (
            id INTEGER PRIMARY KEY AUTOINCREMENT, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, orden INTEGER,
            tipo TEXT, cliente_id INTEGER NULL, domicilio_id INTEGER NULL, ubicacion TEXT, fecha_estimada TEXT NULL,
            fecha_real TEXT NULL, descripcion_carga TEXT NULL, cantidad REAL NULL, unidad_carga_id INTEGER NULL, observaciones TEXT NULL, created_at TEXT NULL
        )');
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testNextNumberIsCorrelativePerCompany(): void
    {
        $repository = new CodeIgniterOperationalTripRepository($this->database);

        self::assertSame(1, $repository->nextNumber(5));
        self::assertSame(2, $repository->nextNumber(5));
        self::assertSame(1, $repository->nextNumber(6));
        self::assertSame(3, $repository->nextNumber(5));
    }

    public function testAddAndStartRoundTripThroughDatabase(): void
    {
        $repository = new CodeIgniterOperationalTripRepository($this->database);
        $number = $repository->nextNumber(5);
        $id = $repository->add($this->trip($number), 1);

        $loaded = $repository->findForUpdate(5, [10], $id);
        self::assertNotNull($loaded);
        self::assertSame(Trip::PLANIFICADO, $loaded->status());
        self::assertSame(7, $loaded->truckId());
        self::assertSame(9, $loaded->trailerId());
        self::assertSame([11], $loaded->driverIds());
        self::assertCount(2, $loaded->stops());
        self::assertSame('Rosario', $loaded->stops()[0]->location());

        $loaded->start(new DateTimeImmutable('2026-10-20 08:10:00'), 145_300);
        $repository->save($loaded, 1);

        $reloaded = $repository->findForUpdate(5, [10], $id);
        self::assertNotNull($reloaded);
        self::assertSame(Trip::EN_CURSO, $reloaded->status());
        self::assertSame(145_300, $reloaded->departureKm());
    }

    public function testFindForUpdateRespectsBranchScope(): void
    {
        $repository = new CodeIgniterOperationalTripRepository($this->database);
        $id = $repository->add($this->trip(1), 1);

        self::assertNull($repository->findForUpdate(5, [20], $id));
        self::assertNull($repository->findForUpdate(6, null, $id));
        self::assertNotNull($repository->findForUpdate(5, [10], $id));
    }

    public function testConflictsDetectsOverlapAndIgnoresNonOverlappingOrFinishedTrips(): void
    {
        $repository = new CodeIgniterOperationalTripRepository($this->database);
        $id = $repository->add($this->trip(1), 1);

        $conflicts = $repository->conflicts(
            5,
            [7],
            [11],
            new DateTimeImmutable('2026-10-20 10:00:00'),
            new DateTimeImmutable('2026-10-20 12:00:00'),
        );
        self::assertCount(2, $conflicts);
        self::assertSame(1, $conflicts[0]->tripNumber);
        self::assertContains($conflicts[0]->role, [TripAssignment::TRACTOR, TripAssignment::CHOFER]);

        self::assertSame([], $repository->conflicts(
            5,
            [7],
            [11],
            new DateTimeImmutable('2026-10-25 10:00:00'),
            new DateTimeImmutable('2026-10-25 12:00:00'),
        ));
        self::assertSame([], $repository->conflicts(
            5,
            [7],
            [11],
            new DateTimeImmutable('2026-10-20 10:00:00'),
            new DateTimeImmutable('2026-10-20 12:00:00'),
            $id,
        ));

        $this->database->table('viajes_operativos')->where('id', $id)->update(['estado' => Trip::CANCELADO]);
        self::assertSame([], $repository->conflicts(
            5,
            [7],
            [11],
            new DateTimeImmutable('2026-10-20 10:00:00'),
            new DateTimeImmutable('2026-10-20 12:00:00'),
        ));
    }

    private function trip(int $number): Trip
    {
        return Trip::plan(
            5,
            10,
            $number,
            new DateTimeImmutable('2026-10-20 08:00:00'),
            new DateTimeImmutable('2026-10-21 18:00:00'),
            [
                new TripAssignment(TripAssignment::TRACTOR, 7, new DateTimeImmutable('2026-10-20 08:00:00'), new DateTimeImmutable('2026-10-21 18:00:00')),
                new TripAssignment(TripAssignment::ACOPLADO, 9, new DateTimeImmutable('2026-10-20 08:00:00'), new DateTimeImmutable('2026-10-21 18:00:00')),
                new TripAssignment(TripAssignment::CHOFER, 11, new DateTimeImmutable('2026-10-20 08:00:00'), new DateTimeImmutable('2026-10-21 18:00:00')),
            ],
            [
                new TripStop(1, TripStop::CARGA, 'Rosario', 3, null, null, 'Soja', '30', 2),
                new TripStop(2, TripStop::DESCARGA, 'Córdoba'),
            ],
            'Carga programada.',
        );
    }
}
