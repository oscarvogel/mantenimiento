<?php

declare(strict_types=1);

use App\Domain\Trips\TripCashMovement;
use App\Domain\Trips\TripMoney;
use App\Infrastructure\Trips\CodeIgniterTripCostRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class TripCostRepositoryAuditReasonTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La prueba del repositorio requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
        ], false);

        $this->database->query('CREATE TABLE viaje_operativo_caja_movimientos (
            id INTEGER PRIMARY KEY,
            viaje_id INTEGER,
            empresa_id INTEGER,
            sucursal_id INTEGER,
            tipo VARCHAR(20),
            fecha DATETIME,
            importe DECIMAL(18,4),
            moneda CHAR(3),
            cotizacion DECIMAL(18,6),
            importe_ars DECIMAL(18,4),
            observaciones TEXT NULL,
            updated_at DATETIME NULL,
            updated_by INTEGER NULL,
            deleted_at DATETIME NULL,
            deleted_by INTEGER NULL
        )');

        $this->database->query('CREATE TABLE viaje_operativo_movimientos_auditoria (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            empresa_id INTEGER,
            sucursal_id INTEGER,
            viaje_id INTEGER,
            movimiento_tipo VARCHAR(20),
            movimiento_id INTEGER,
            accion VARCHAR(20),
            datos_anteriores TEXT NULL,
            datos_nuevos TEXT NULL,
            usuario_id INTEGER,
            created_at DATETIME
        )');

        $this->database->table('viaje_operativo_caja_movimientos')->insert([
            'id' => 77,
            'viaje_id' => 55,
            'empresa_id' => 5,
            'sucursal_id' => 10,
            'tipo' => 'ANTICIPO',
            'fecha' => '2026-11-14 17:48:00',
            'importe' => 300.0000,
            'moneda' => 'BRL',
            'cotizacion' => 280.000000,
            'importe_ars' => 84000.0000,
            'observaciones' => 'PIX Mariela',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testMonetaryChangeStoresReasonInAuditPayload(): void
    {
        $repo = new CodeIgniterTripCostRepository($this->database);
        $movement = new TripCashMovement('ANTICIPO', new DateTimeImmutable('2026-11-14 17:48:00'), new TripMoney(300.0, 'BRL', 290.0), 'PIX Mariela');

        $updated = $repo->updateCashMovement(55, 5, 77, $movement, 9, 'Corrección de cotización según comprobante');

        self::assertTrue($updated);
        $audit = $this->database->table('viaje_operativo_movimientos_auditoria')->orderBy('id', 'DESC')->get()->getRowArray();
        self::assertNotNull($audit);
        $before = json_decode((string) $audit['datos_anteriores'], true, 512, JSON_THROW_ON_ERROR);
        $after = json_decode((string) $audit['datos_nuevos'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(280.0, (float) $before['cotizacion']);
        self::assertSame(290.0, (float) $after['cotizacion']);
        self::assertSame('Corrección de cotización según comprobante', $after['motivo_cambio'] ?? null);
        self::assertSame(9, (int) $audit['usuario_id']);
    }

    public function testNotesOnlyChangeDoesNotStoreMonetaryReason(): void
    {
        $repo = new CodeIgniterTripCostRepository($this->database);
        $movement = new TripCashMovement('ANTICIPO', new DateTimeImmutable('2026-11-14 17:48:00'), new TripMoney(300.0, 'BRL', 280.0), 'Nota nueva');

        $updated = $repo->updateCashMovement(55, 5, 77, $movement, 9, 'motivo ignorado si no hay cambio monetario');

        self::assertTrue($updated);
        $audit = $this->database->table('viaje_operativo_movimientos_auditoria')->orderBy('id', 'DESC')->get()->getRowArray();
        $after = json_decode((string) $audit['datos_nuevos'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Nota nueva', $after['observaciones']);
        self::assertArrayNotHasKey('motivo_cambio', $after);
    }
}
