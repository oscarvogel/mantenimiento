<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\GetTripsDashboard;
use App\Application\Trips\TripDashboardFilters;
use App\Infrastructure\Trips\CodeIgniterTripDashboardReadModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterTripDashboardReadModelTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de filtros del dashboard requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo TEXT, nombre TEXT, estado INTEGER, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE viajes (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, origen TEXT, dominio_origen TEXT, numero_viaje INTEGER, fecha_salida TEXT, fecha_regreso TEXT NULL, km_salida INTEGER NULL, km_regreso INTEGER NULL)');
        $this->database->query('CREATE TABLE viaje_combustible (id INTEGER PRIMARY KEY, viaje_id INTEGER, litros REAL NULL)');
        $this->database->table('sucursales')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'codigo' => 'AR', 'nombre' => 'Argentina', 'estado' => 1, 'deleted_at' => null],
            ['id' => 20, 'empresa_id' => 5, 'codigo' => 'BR', 'nombre' => 'Brasil', 'estado' => 1, 'deleted_at' => null],
            ['id' => 30, 'empresa_id' => 6, 'codigo' => 'OTR', 'nombre' => 'Otra empresa', 'estado' => 1, 'deleted_at' => null],
        ]);
        $this->database->table('viajes')->insertBatch([
            $this->trip(1, 5, 10, 'AC 532 DD', '2026-09-03', 100, 200),
            $this->trip(2, 5, 10, 'AC 532 DD', '2026-09-04', 200, null),
            $this->trip(3, 5, 20, 'IUU5G88', '2026-09-05', 300, 400),
            $this->trip(4, 5, 10, 'AC 532 DD', '2026-08-31', 400, 500),
            $this->trip(5, 6, 30, 'OTRA1', '2026-09-05', 500, 600),
        ]);
        $this->database->table('viaje_combustible')->insertBatch([
            ['id' => 1, 'viaje_id' => 1, 'litros' => 20],
            ['id' => 2, 'viaje_id' => 3, 'litros' => 500],
            ['id' => 3, 'viaje_id' => 4, 'litros' => 300],
            ['id' => 4, 'viaje_id' => 5, 'litros' => 700],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testQualityAndPerDomainAggregatesUseTheAppliedDateBranchAndDomainFilters(): void
    {
        $dashboard = new GetTripsDashboard(new CodeIgniterTripDashboardReadModel($this->database));

        $payload = $dashboard->execute(
            new ActorContext(7, 5, false, true, [], ['viajes.ver'], []),
            new TripDashboardFilters('2026-09-01', '2026-09-30', 10, 'AC 532 DD'),
        );

        self::assertSame(2, $payload['summary']['trips']);
        self::assertSame(100, $payload['summary']['km']);
        self::assertSame(1, $payload['summary']['km_trips']);
        self::assertSame(1, $payload['summary']['missing_readings']);
        self::assertSame(20.0, $payload['summary']['liters']);
        self::assertSame(1, $payload['quality'][0]['value']);
        self::assertSame(2, $payload['quality'][0]['total']);
        self::assertCount(1, $payload['domains']);
        self::assertSame('AC 532 DD', $payload['domains'][0]['domain']);
        self::assertSame(2, $payload['domains'][0]['trips']);
        self::assertSame(100, $payload['domains'][0]['km']);
        self::assertSame(20.0, $payload['domains'][0]['liters']);
    }

    /** @return array<string,mixed> */
    private function trip(int $id, int $companyId, int $branchId, string $domain, string $date, ?int $startKm, ?int $endKm): array
    {
        return [
            'id' => $id, 'empresa_id' => $companyId, 'sucursal_id' => $branchId,
            'origen' => 'LEGACY_TRANSPORTE', 'dominio_origen' => $domain, 'numero_viaje' => $id,
            'fecha_salida' => $date, 'fecha_regreso' => null, 'km_salida' => $startKm, 'km_regreso' => $endKm,
        ];
    }
}
