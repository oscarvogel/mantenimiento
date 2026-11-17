<?php

declare(strict_types=1);

use App\Domain\Trips\Trip;
use App\Domain\Trips\TripAssignment;
use App\Domain\Trips\TripLeg;
use App\Domain\Trips\TripLegAmount;
use App\Domain\Trips\TripLegLiquidationBasis;
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
            moneda TEXT, cotizacion REAL, moneda_liquidacion TEXT NULL, cotizacion_liquidacion REAL NULL, modo_liquidacion TEXT NULL, toneladas_liquidacion REAL NULL, precio_unitario_liquidacion REAL NULL, flete_facturacion REAL, flete_facturacion_ars REAL,
            flete_liquidacion REAL, flete_liquidacion_ars REAL, fecha_inicio TEXT NULL, fecha_fin TEXT NULL, observaciones TEXT NULL,
            created_at TEXT NULL, updated_at TEXT NULL, created_by INTEGER NULL, updated_by INTEGER NULL
        )');
        $this->database->query('CREATE TABLE viaje_operativo_movimientos_auditoria (
            id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER, sucursal_id INTEGER, viaje_id INTEGER,
            movimiento_tipo TEXT, movimiento_id INTEGER, accion TEXT, datos_anteriores TEXT NULL, datos_nuevos TEXT NULL,
            usuario_id INTEGER NULL, created_at TEXT
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
        $first = $repository->add(5, 10, 55, new TripLeg(
            'Rosario',
            'Córdoba',
            new TripLegAmount(1_000_000, 'ARS'),
            new TripLegAmount(800_000, 'ARS'),
            320,
            3,
            null,
            947,
            24.5,
            101,
            202,
        ), 1);
        $second = $repository->add(5, 10, 55, new TripLeg('Córdoba', 'Rosario', new TripLegAmount(600_000), new TripLegAmount(500_000), 320), 1);

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

    public function testPersistsEachFreightWithItsOwnCurrency(): void
    {
        $repository = new CodeIgniterTripLegRepository($this->database);
        $repository->add(5, 10, 55, new TripLeg(
            'Rosario',
            'Córdoba',
            new TripLegAmount(4_700, 'USD', 1_250.0),
            new TripLegAmount(5_875_000, 'ARS'),
        ), 1);

        $row = $this->database->table('viaje_operativo_tramos')->orderBy('orden')->get()->getRowArray();
        self::assertSame('USD', $row['moneda']);
        self::assertEquals(1_250.0, (float) $row['cotizacion']);
        self::assertEquals(4_700.0, (float) $row['flete_facturacion']);
        self::assertEquals(5_875_000.0, (float) $row['flete_facturacion_ars']);

        self::assertSame('ARS', $row['moneda_liquidacion']);
        self::assertEquals(1.0, (float) $row['cotizacion_liquidacion']);
        self::assertEquals(5_875_000.0, (float) $row['flete_liquidacion']);
        self::assertEquals(5_875_000.0, (float) $row['flete_liquidacion_ars']);
    }

    public function testPersistsMissingAmountsAndReportsLegsThatStillNeedThem(): void
    {
        $repository = new CodeIgniterTripLegRepository($this->database);
        $first = $repository->add(5, 10, 55, new TripLeg('Rosario', 'Córdoba', new TripLegAmount(null), new TripLegAmount(null)), 1);
        $second = $repository->add(5, 10, 55, new TripLeg('Córdoba', 'Rosario', new TripLegAmount(600_000), new TripLegAmount(500_000)), 1);

        $rows = $this->database->table('viaje_operativo_tramos')->orderBy('orden')->get()->getResultArray();
        self::assertNull($rows[0]['flete_facturacion']);
        self::assertNull($rows[0]['flete_liquidacion']);
        self::assertNull($rows[0]['flete_facturacion_ars']);
        self::assertNull($rows[0]['flete_liquidacion_ars']);
        self::assertSame([1], $repository->ordersWithMissingAmounts(5, 55));

        $repository->update(5, 10, 55, $first, new TripLeg('Rosario', 'Córdoba', new TripLegAmount(1_000_000), new TripLegAmount(800_000)), 1);

        $updated = $this->database->table('viaje_operativo_tramos')->where('id', $first)->get()->getRowArray();
        self::assertEquals(1_000_000.0, (float) $updated['flete_facturacion']);
        self::assertEquals(800_000.0, (float) $updated['flete_liquidacion']);
        self::assertSame([], $repository->ordersWithMissingAmounts(5, 55));
        self::assertTrue($repository->belongsToTrip(5, 55, $second));
    }

    public function testPersistsTheDatesOfTheLeg(): void
    {
        $repository = new CodeIgniterTripLegRepository($this->database);
        $first = $repository->add(5, 10, 55, new TripLeg(
            'Rosario',
            'Córdoba',
            new TripLegAmount(null),
            new TripLegAmount(null),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            new DateTimeImmutable('2026-11-02'),
            new DateTimeImmutable('2026-11-04'),
        ), 1);
        $second = $repository->add(5, 10, 55, new TripLeg('Córdoba', 'Rosario', new TripLegAmount(null), new TripLegAmount(null)), 1);

        $rows = $this->database->table('viaje_operativo_tramos')->orderBy('orden')->get()->getResultArray();
        self::assertSame('2026-11-02', $rows[0]['fecha_inicio']);
        self::assertSame('2026-11-04', $rows[0]['fecha_fin']);
        self::assertNull($rows[1]['fecha_inicio']);
        self::assertNull($rows[1]['fecha_fin']);

        $repository->update(5, 10, 55, $first, new TripLeg(
            'Rosario',
            'Córdoba',
            new TripLegAmount(null),
            new TripLegAmount(null),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            new DateTimeImmutable('2026-11-03'),
        ), 1);

        $updated = $this->database->table('viaje_operativo_tramos')->where('id', $first)->get()->getRowArray();
        self::assertSame('2026-11-03', $updated['fecha_inicio']);
        self::assertNull($updated['fecha_fin']);
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

    public function testPersistsThePerTonneAgreementWithItsComputedBase(): void
    {
        $repository = new CodeIgniterTripLegRepository($this->database);
        $repository->add(5, 10, 55, new TripLeg(
            'Rosario',
            'Córdoba',
            new TripLegAmount(2_000_000),
            new TripLegAmount(1_350_000),
            tonnes: 31.2,
            liquidationBasis: TripLegLiquidationBasis::perTonne(30, 45_000),
        ), 1);

        $row = $this->database->table('viaje_operativo_tramos')->get()->getRowArray();
        self::assertSame('POR_TONELADA', $row['modo_liquidacion']);
        self::assertEquals(30.0, (float) $row['toneladas_liquidacion']);
        self::assertEquals(45_000.0, (float) $row['precio_unitario_liquidacion']);
        self::assertEquals(1_350_000.0, (float) $row['flete_liquidacion_ars']);
        // Las toneladas operativas del tramo no se mezclan con el acuerdo.
        self::assertEquals(31.2, (float) $row['toneladas']);
        self::assertEquals(2_000_000.0, (float) $row['flete_facturacion_ars']);
    }

    public function testAnIncompletePerTonneAgreementBlocksTheClosingLikeAMissingFreight(): void
    {
        $repository = new CodeIgniterTripLegRepository($this->database);
        $repository->add(5, 10, 55, new TripLeg(
            'Rosario',
            'Córdoba',
            new TripLegAmount(2_000_000),
            new TripLegAmount(null),
            liquidationBasis: TripLegLiquidationBasis::perTonne(30, null),
        ), 1);

        self::assertSame([1], $repository->ordersWithMissingAmounts(5, 55));
    }

    public function testAuditsWhoChangedTheLegAndWhatItWasBefore(): void
    {
        $repository = new CodeIgniterTripLegRepository($this->database);
        $id = $repository->add(5, 10, 55, new TripLeg('Rosario', 'Córdoba', new TripLegAmount(2_000_000), new TripLegAmount(1_250_000)), 1);

        $repository->update(5, 10, 55, $id, new TripLeg(
            'Rosario',
            'Córdoba',
            new TripLegAmount(2_000_000),
            new TripLegAmount(1_350_000),
            liquidationBasis: TripLegLiquidationBasis::perTonne(30, 45_000),
        ), 7);

        $audit = $this->database->table('viaje_operativo_movimientos_auditoria')->get()->getResultArray();
        self::assertCount(1, $audit);
        self::assertSame('TRAMO', $audit[0]['movimiento_tipo']);
        self::assertSame('EDICION', $audit[0]['accion']);
        self::assertSame($id, (int) $audit[0]['movimiento_id']);
        self::assertSame(7, (int) $audit[0]['usuario_id']);
        $before = json_decode((string) $audit[0]['datos_anteriores'], true, 512, JSON_THROW_ON_ERROR);
        $after = json_decode((string) $audit[0]['datos_nuevos'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('MONTO_ACORDADO', $before['modo_liquidacion']);
        self::assertEquals(1_250_000.0, (float) $before['flete_liquidacion']);
        self::assertSame('POR_TONELADA', $after['modo_liquidacion']);
        self::assertEquals(1_350_000.0, (float) $after['flete_liquidacion']);
        // Editar la liquidación no toca el flete comercial.
        self::assertEquals((float) $before['flete_facturacion'], (float) $after['flete_facturacion']);
    }
}
