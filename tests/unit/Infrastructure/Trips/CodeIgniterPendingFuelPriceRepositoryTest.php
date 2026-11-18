<?php

declare(strict_types=1);

use App\Domain\Trips\TripMoney;
use App\Infrastructure\Trips\CodeIgniterPendingFuelPriceRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterPendingFuelPriceRepositoryTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('El repositorio de combustible sin precio requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $db = $this->database;
        $db->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre VARCHAR(120))');
        $db->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo VARCHAR(40), patente VARCHAR(20))');
        $db->query('CREATE TABLE viajes_operativos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, numero INTEGER, estado VARCHAR(20))');
        $db->query('CREATE TABLE viaje_operativo_asignaciones (id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, rol VARCHAR(20), equipo_id INTEGER NULL, empleado_id INTEGER NULL)');
        $db->query('CREATE TABLE viaje_operativo_combustible (
            id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, tramo_id INTEGER NULL,
            fecha DATETIME, litros DECIMAL(14,2), importe DECIMAL(18,4) NULL, moneda CHAR(3) DEFAULT \'ARS\',
            cotizacion DECIMAL(18,6) DEFAULT 1, importe_ars DECIMAL(18,4) NULL, kilometraje INTEGER NULL,
            estacion VARCHAR(120) NULL, comprobante VARCHAR(80) NULL, observaciones VARCHAR(500) NULL,
            created_at DATETIME NULL, created_by INTEGER NULL, updated_at DATETIME NULL, updated_by INTEGER NULL,
            deleted_at DATETIME NULL, deleted_by INTEGER NULL
        )');
        $db->query('CREATE TABLE viaje_operativo_movimientos_auditoria (
            id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, viaje_id INTEGER, movimiento_tipo VARCHAR(20),
            movimiento_id INTEGER, accion VARCHAR(20), datos_anteriores TEXT NULL, datos_nuevos TEXT NULL, usuario_id INTEGER, created_at DATETIME
        )');
        $db->query('CREATE TABLE viajes_monedas (codigo CHAR(3) PRIMARY KEY, nombre VARCHAR(60), activo INTEGER)');
        $db->query('CREATE TABLE viajes_cotizaciones (id INTEGER PRIMARY KEY, empresa_id INTEGER, moneda CHAR(3), cotizacion DECIMAL(18,6))');

        $db->table('sucursales')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'nombre' => 'Central'],
            ['id' => 20, 'empresa_id' => 5, 'nombre' => 'Norte'],
        ]);
        $db->table('equipos')->insert(['id' => 7, 'empresa_id' => 5, 'codigo' => 'T01', 'patente' => 'AA111AA']);
        $db->table('viajes_operativos')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'sucursal_id' => 10, 'numero' => 11, 'estado' => 'FINALIZADO'],
            ['id' => 2, 'empresa_id' => 5, 'sucursal_id' => 20, 'numero' => 12, 'estado' => 'EN_CURSO'],
            ['id' => 3, 'empresa_id' => 5, 'sucursal_id' => 10, 'numero' => 13, 'estado' => 'CANCELADO'],
            ['id' => 4, 'empresa_id' => 6, 'sucursal_id' => 10, 'numero' => 14, 'estado' => 'EN_CURSO'],
        ]);
        $db->table('viaje_operativo_asignaciones')->insert(['id' => 1, 'viaje_id' => 1, 'empresa_id' => 5, 'rol' => 'TRACTOR', 'equipo_id' => 7]);
        $fuel = static fn (int $id, int $trip, int $company, int $branch, string $date, float $liters, ?float $amount = null, ?string $deleted = null): array => [
            'id' => $id, 'viaje_id' => $trip, 'empresa_id' => $company, 'sucursal_id' => $branch, 'fecha' => $date,
            'litros' => $liters, 'importe' => $amount, 'moneda' => 'ARS', 'cotizacion' => 1, 'importe_ars' => $amount,
            'estacion' => 'Playa propia', 'deleted_at' => $deleted,
        ];
        $db->table('viaje_operativo_combustible')->insertBatch([
            $fuel(1, 1, 5, 10, '2026-11-15 08:00:00', 300.0),
            $fuel(2, 2, 5, 20, '2026-11-14 08:00:00', 250.0),
            $fuel(3, 1, 5, 10, '2026-11-15 09:00:00', 100.0, 145000.0),
            $fuel(4, 1, 5, 10, '2026-11-15 10:00:00', 80.0, null, '2026-11-16 10:00:00'),
            $fuel(5, 3, 5, 10, '2026-11-15 11:00:00', 50.0),
            $fuel(6, 4, 6, 10, '2026-11-15 12:00:00', 60.0),
        ]);
        $db->table('viajes_monedas')->insertBatch([
            ['codigo' => 'ARS', 'nombre' => 'Peso argentino', 'activo' => 1],
            ['codigo' => 'USD', 'nombre' => 'Dólar', 'activo' => 1],
        ]);
        $db->table('viajes_cotizaciones')->insert(['id' => 1, 'empresa_id' => 5, 'moneda' => 'USD', 'cotizacion' => 1490]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testListsOnlyActiveLoadsWithoutPriceOfTheScope(): void
    {
        $repository = new CodeIgniterPendingFuelPriceRepository($this->database);

        $rows = $repository->pending(5, null);
        self::assertSame([2, 1], array_column($rows, 'id'));
        self::assertSame('AA111AA · T01', $rows[1]['tractor']);
        self::assertSame(11, $rows[1]['viaje_numero']);
        self::assertSame('FINALIZADO', $rows[1]['viaje_estado']);
        self::assertSame(300.0, $rows[1]['litros']);
        self::assertNull($rows[0]['tractor']);

        self::assertSame([1], array_column($repository->pending(5, [10]), 'id'));
        self::assertSame([], $repository->pending(5, []));
        self::assertSame([6], array_column($repository->pending(6, null), 'id'));
    }

    public function testCompletesThePriceOnceAndAuditsIt(): void
    {
        $repository = new CodeIgniterPendingFuelPriceRepository($this->database);

        self::assertSame(['id' => 1, 'viaje_id' => 1, 'viaje_numero' => 11, 'viaje_estado' => 'FINALIZADO', 'con_precio' => false], $repository->findForUpdate(5, [10], 1));
        self::assertTrue($repository->completePrice(5, 1, new TripMoney(100.0, 'USD', 1450.0), 42));
        $row = $this->database->table('viaje_operativo_combustible')->where('id', 1)->get()->getRowArray();
        self::assertSame(100.0, (float) $row['importe']);
        self::assertSame('USD', $row['moneda']);
        self::assertSame(145000.0, (float) $row['importe_ars']);
        self::assertSame(42, (int) $row['updated_by']);

        // Ya tiene precio: no se pisa.
        self::assertFalse($repository->completePrice(5, 1, new TripMoney(1.0), 42));
        self::assertTrue($repository->findForUpdate(5, null, 1)['con_precio']);
        self::assertSame(145000.0, (float) $this->database->table('viaje_operativo_combustible')->where('id', 1)->get()->getRowArray()['importe_ars']);

        $audit = $this->database->table('viaje_operativo_movimientos_auditoria')->get()->getResultArray();
        self::assertCount(1, $audit);
        self::assertSame('PRECIO', $audit[0]['accion']);
        self::assertSame('COMBUSTIBLE', $audit[0]['movimiento_tipo']);
        self::assertNull(json_decode((string) $audit[0]['datos_anteriores'], true)['importe']);
    }

    public function testDoesNotFindLoadsOutsideTheScopeOrRemoved(): void
    {
        $repository = new CodeIgniterPendingFuelPriceRepository($this->database);

        self::assertNull($repository->findForUpdate(5, [10], 2));
        self::assertNull($repository->findForUpdate(5, null, 4));
        self::assertNull($repository->findForUpdate(5, null, 6));
        self::assertNull($repository->findForUpdate(5, [], 1));
        self::assertFalse($repository->completePrice(5, 4, new TripMoney(10.0), 42));
        self::assertFalse($repository->completePrice(5, 6, new TripMoney(10.0), 42));
    }

    public function testCurrenciesCarryTheCompanyRate(): void
    {
        self::assertSame([
            ['code' => 'ARS', 'label' => 'Peso argentino', 'rate' => 1.0],
            ['code' => 'USD', 'label' => 'Dólar', 'rate' => 1490.0],
        ], (new CodeIgniterPendingFuelPriceRepository($this->database))->currencies(5));
    }
}
