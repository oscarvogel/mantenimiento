<?php

declare(strict_types=1);

use App\Infrastructure\Trips\CodeIgniterTripLegCancellationStore;
use App\Infrastructure\Trips\CodeIgniterTripLegRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterTripLegCancellationStoreTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La anulación de tramos requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE viaje_operativo_tramos (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, orden INTEGER,
            origen TEXT, destino TEXT, flete_facturacion REAL NULL, flete_liquidacion REAL NULL, fecha_fin TEXT NULL,
            updated_at TEXT NULL, updated_by INTEGER NULL, anulado_at TEXT NULL, anulado_por INTEGER NULL, motivo_anulacion TEXT NULL
        )');
        foreach (['viaje_operativo_combustible', 'viaje_operativo_gastos'] as $table) {
            $this->database->query('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, viaje_id INTEGER, tramo_id INTEGER NULL, empresa_id INTEGER, deleted_at TEXT NULL)');
        }
        $this->database->query('CREATE TABLE viaje_operativo_movimientos_auditoria (
            id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER, sucursal_id INTEGER, viaje_id INTEGER,
            movimiento_tipo TEXT, movimiento_id INTEGER, accion TEXT, datos_anteriores TEXT NULL, datos_nuevos TEXT NULL,
            usuario_id INTEGER NULL, created_at TEXT
        )');
        $this->database->table('viaje_operativo_tramos')->insertBatch([
            ['id' => 1, 'viaje_id' => 55, 'empresa_id' => 5, 'sucursal_id' => 10, 'orden' => 1, 'origen' => 'Mendoza', 'destino' => 'Dionisio', 'flete_facturacion' => 100, 'flete_liquidacion' => 80, 'fecha_fin' => '2026-11-18'],
            ['id' => 2, 'viaje_id' => 55, 'empresa_id' => 5, 'sucursal_id' => 10, 'orden' => 2, 'origen' => 'Dionisio', 'destino' => 'Mendoza', 'flete_facturacion' => null, 'flete_liquidacion' => null, 'fecha_fin' => '2026-11-20'],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testStateCountsOnlyLiveCostsOfTheLegAndIgnoresOtherTrips(): void
    {
        $this->database->table('viaje_operativo_combustible')->insertBatch([
            ['id' => 1, 'viaje_id' => 55, 'tramo_id' => 1, 'empresa_id' => 5, 'deleted_at' => null],
            ['id' => 2, 'viaje_id' => 55, 'tramo_id' => 1, 'empresa_id' => 5, 'deleted_at' => '2026-11-19 10:00:00'],
        ]);
        $this->database->table('viaje_operativo_gastos')->insert(['id' => 1, 'viaje_id' => 55, 'tramo_id' => 1, 'empresa_id' => 5, 'deleted_at' => null]);
        $store = new CodeIgniterTripLegCancellationStore($this->database);

        self::assertSame(['cancelled' => false, 'assigned_costs' => 2], $store->state(5, 55, 1));
        self::assertSame(['cancelled' => false, 'assigned_costs' => 0], $store->state(5, 55, 2));
        self::assertNull($store->state(5, 99, 1));
        self::assertNull($store->state(6, 55, 1));
    }

    public function testCancelMarksTheLegAuditsItAndTakesItOutOfTheLegQueries(): void
    {
        $store = new CodeIgniterTripLegCancellationStore($this->database);
        $legs = new CodeIgniterTripLegRepository($this->database);
        self::assertSame([2], $legs->ordersWithMissingAmounts(5, 55));
        self::assertSame('2026-11-20', $legs->lastLegEndDate(5, 55)?->format('Y-m-d'));

        $store->cancel(5, 10, 55, 2, 'Cargado por error', 7);

        $row = $this->database->table('viaje_operativo_tramos')->where('id', 2)->get()->getRowArray();
        self::assertNotNull($row['anulado_at']);
        self::assertSame(7, (int) $row['anulado_por']);
        self::assertSame('Cargado por error', $row['motivo_anulacion']);
        self::assertSame(['cancelled' => true, 'assigned_costs' => 0], $store->state(5, 55, 2));

        $audit = $this->database->table('viaje_operativo_movimientos_auditoria')->get()->getRowArray();
        self::assertSame('TRAMO', $audit['movimiento_tipo']);
        self::assertSame('ANULACION', $audit['accion']);
        self::assertSame(2, (int) $audit['movimiento_id']);
        self::assertNull(json_decode((string) $audit['datos_anteriores'], true)['anulado_at']);
        self::assertSame('Cargado por error', json_decode((string) $audit['datos_nuevos'], true)['motivo_anulacion']);

        // El tramo anulado ya no bloquea el cierre ni define el regreso, y no se edita ni recibe costos.
        self::assertSame([], $legs->ordersWithMissingAmounts(5, 55));
        self::assertSame('2026-11-18', $legs->lastLegEndDate(5, 55)?->format('Y-m-d'));
        self::assertFalse($legs->belongsToTrip(5, 55, 2));
        self::assertTrue($legs->belongsToTrip(5, 55, 1));
    }

    public function testCancellingTwiceFails(): void
    {
        $store = new CodeIgniterTripLegCancellationStore($this->database);
        $store->cancel(5, 10, 55, 2, 'Cargado por error', 7);

        $this->expectException(RuntimeException::class);
        $store->cancel(5, 10, 55, 2, 'Otra vez', 7);
    }
}
