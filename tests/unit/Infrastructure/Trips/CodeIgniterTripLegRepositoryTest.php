<?php

declare(strict_types=1);

use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use App\Domain\Trips\TripLeg;
use App\Domain\Trips\TripStop;
use App\Infrastructure\Trips\CodeIgniterTripLegRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterTripLegRepositoryTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de tramos requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE viaje_operativo_tramos (
            id INTEGER PRIMARY KEY AUTOINCREMENT, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER,
            orden INTEGER, codigo_origen_destino INTEGER NULL, localidad_origen_id INTEGER NULL, localidad_destino_id INTEGER NULL, cliente_id INTEGER NULL, origen TEXT, destino TEXT, km REAL NULL, toneladas REAL NULL,
            moneda TEXT, cotizacion REAL, flete_facturacion REAL, flete_facturacion_ars REAL,
            flete_liquidacion REAL, flete_liquidacion_ars REAL, observaciones TEXT NULL,
            created_at TEXT NULL, updated_at TEXT NULL, created_by INTEGER NULL, updated_by INTEGER NULL
        )');
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testAddsLegsInOrderAndValidatesTripScope(): void
    {
        $repository = new CodeIgniterTripLegRepository($this->database);
        $first = $repository->add(5, 10, 55, new TripLeg('Rosario', 'Córdoba', 1_000_000, 800_000, 320, 3, 'ARS', 1.0, null, 947, 24.5, 101, 202), 1);
        $second = $repository->add(5, 10, 55, new TripLeg('Córdoba', 'Rosario', 600_000, 500_000, 320), 1);

        $rows = $this->database->table('viaje_operativo_tramos')->orderBy('orden')->get()->getResultArray();
        self::assertCount(2, $rows);
        self::assertSame(1, (int) $rows[0]['orden']);
        self::assertSame(2, (int) $rows[1]['orden']);
        self::assertSame('Rosario', $rows[0]['origen']);
        self::assertSame(947, (int) $rows[0]['codigo_origen_destino']);
        self::assertSame(101, (int) $rows[0]['localidad_origen_id']);
        self::assertSame(202, (int) $rows[0]['localidad_destino_id']);
        self::assertEquals(24.5, (float) $rows[0]['toneladas']);
        self::assertEquals(800_000.0, (float) $rows[0]['flete_liquidacion']);
        self::assertTrue($repository->belongsToTrip(5, 55, $first));
        self::assertTrue($repository->belongsToTrip(5, 55, $second));
        self::assertFalse($repository->belongsToTrip(6, 55, $first));
        self::assertFalse($repository->belongsToTrip(5, 56, $first));
    }

    private function trip(): Trip
    {
        return Trip::reconstitute(
            55,
            5,
            10,
            42,
            Trip::PLANIFICADO,
            new DateTimeImmutable('2026-10-20 08:00:00'),
            new DateTimeImmutable('2026-10-21 18:00:00'),
            null,
            null,
            null,
            null,
            [
                new TripAssignment(TripAssignment::TRACTOR, 7, new DateTimeImmutable('2026-10-20 08:00:00')),
                new TripAssignment(TripAssignment::CHOFER, 11, new DateTimeImmutable('2026-10-20 08:00:00')),
            ],
            [
                new TripStop(1, TripStop::CARGA, 'Rosario'),
                new TripStop(2, TripStop::DESCARGA, 'Córdoba'),
            ],
            '',
        );
    }
}
