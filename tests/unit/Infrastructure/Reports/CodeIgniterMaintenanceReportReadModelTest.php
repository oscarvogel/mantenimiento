<?php

declare(strict_types=1);

use App\Application\Reports\ReportScope;
use App\Infrastructure\Reports\CodeIgniterMaintenanceReportReadModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterMaintenanceReportReadModelTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión del reporte requiere sqlite3.');
        }
        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo TEXT, nombre TEXT, estado INTEGER, deleted_at TEXT NULL)');
        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, codigo TEXT)');
        $this->database->query(
            'CREATE TABLE ordenes_trabajo (id INTEGER PRIMARY KEY, numero TEXT, empresa_id INTEGER, sucursal_id INTEGER, equipo_id INTEGER, origen TEXT, prioridad TEXT, fecha_apertura TEXT, fecha_inicio TEXT NULL, fecha_finalizacion TEXT NULL, estado TEXT, inicio_detencion TEXT NULL, fin_detencion TEXT NULL, costo_total TEXT)'
        );
        $this->database->table('sucursales')->insertBatch([
            ['id' => 9, 'empresa_id' => 5, 'codigo' => 'CEN', 'nombre' => 'Central', 'estado' => 1, 'deleted_at' => null],
            ['id' => 8, 'empresa_id' => 5, 'codigo' => 'SUR', 'nombre' => 'Sur', 'estado' => 1, 'deleted_at' => null],
            ['id' => 4, 'empresa_id' => 6, 'codigo' => 'OTR', 'nombre' => 'Otra', 'estado' => 1, 'deleted_at' => null],
        ]);
        $this->database->table('equipos')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'sucursal_id' => 9, 'codigo' => 'SCANIA-01'],
            ['id' => 11, 'empresa_id' => 5, 'sucursal_id' => 8, 'codigo' => 'VOLVO-02'],
            ['id' => 12, 'empresa_id' => 6, 'sucursal_id' => 4, 'codigo' => 'AJENO-01'],
        ]);
        $this->database->table('ordenes_trabajo')->insertBatch([
            $this->order(1, 'OT-001', 5, 9, 10, 'CORRECTIVO', 'FINALIZADA', '2026-08-01 08:00:00', '2026-08-02 08:00:00', '2026-08-02 10:00:00', '2026-08-02 07:00:00', '2026-08-02 10:00:00', '100.50'),
            $this->order(2, 'OT-002', 5, 9, 10, 'PREVENTIVO_VENCIDO', 'FINALIZADA', '2026-08-03 08:00:00', '2026-08-04 08:00:00', '2026-08-04 09:00:00', '2026-08-04 10:00:00', '2026-08-04 09:00:00', '50.00'),
            $this->order(3, 'OT-003', 5, 9, 10, 'PREVENTIVO_VENCIDO', 'EN_PROCESO', '2026-08-05 08:00:00', '2026-08-05 09:00:00', null, null, null, '0.00'),
            $this->order(4, 'OT-004', 5, 8, 11, 'CORRECTIVO', 'FINALIZADA', '2026-08-02 08:00:00', '2026-08-03 08:00:00', '2026-08-03 12:00:00', null, null, '999.00'),
            $this->order(5, 'OT-005', 6, 4, 12, 'CORRECTIVO', 'FINALIZADA', '2026-08-02 08:00:00', '2026-08-03 08:00:00', '2026-08-03 12:00:00', null, null, '888.00'),
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testBuildsMetricsOnlyFromCompanyBranchAndValidDurations(): void
    {
        $readModel = new CodeIgniterMaintenanceReportReadModel($this->database);
        $scope = new ReportScope(
            5, [9], 9, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-31'), 1, 20,
        );
        $report = $readModel->fetch($scope);

        self::assertSame('150.50', $report['metrics']['totalCost']['value']);
        self::assertSame(1, $report['metrics']['openOrders']['value']);
        self::assertSame(2, $report['metrics']['completedOrders']['value']);
        self::assertSame('3.0', $report['metrics']['downtimeHours']['value']);
        self::assertSame(1, $report['metrics']['downtimeHours']['sampleSize']);
        self::assertSame('2.0', $report['metrics']['mttrHours']['value']);
        self::assertSame(1, $report['quality']['invalidDowntimeSamples']);
        self::assertSame(3, $report['orders']['pagination']['total']);
        self::assertSame('SCANIA-01', $report['costsByEquipment'][0]['equipmentCode']);
        self::assertSame(['OT-003', 'OT-002', 'OT-001'], array_column($readModel->exportOrders($scope), 'numero'));
    }

    public function testReportsUnavailableDurationsInsteadOfInventingValues(): void
    {
        $report = (new CodeIgniterMaintenanceReportReadModel($this->database))->fetch(new ReportScope(
            5, [8], 8, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-31'), 1, 20,
        ));

        self::assertFalse($report['metrics']['downtimeHours']['available']);
        self::assertSame('Sin datos suficientes', $report['metrics']['downtimeHours']['label']);
    }

    /** @return array<string, mixed> */
    private function order(int $id, string $number, int $company, int $branch, int $equipment, string $origin, string $status, string $opened, ?string $started, ?string $completed, ?string $stopStart, ?string $stopEnd, string $cost): array
    {
        return [
            'id' => $id, 'numero' => $number, 'empresa_id' => $company, 'sucursal_id' => $branch,
            'equipo_id' => $equipment, 'origen' => $origin, 'prioridad' => 'MEDIA', 'fecha_apertura' => $opened,
            'fecha_inicio' => $started, 'fecha_finalizacion' => $completed, 'estado' => $status,
            'inicio_detencion' => $stopStart, 'fin_detencion' => $stopEnd, 'costo_total' => $cost,
        ];
    }
}
