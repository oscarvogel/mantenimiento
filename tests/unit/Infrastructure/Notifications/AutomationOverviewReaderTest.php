<?php

declare(strict_types=1);

use App\Infrastructure\Notifications\CodeIgniterAutomationOverviewReader;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class AutomationOverviewReaderTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de automatizaciones requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);

        $this->database->query('CREATE TABLE empresas (id INTEGER PRIMARY KEY, deleted_at TEXT NULL, informe_diario_habilitado INTEGER DEFAULT 0, informe_diario_hora TEXT DEFAULT "07:00", informe_semanal_habilitado INTEGER DEFAULT 0, informe_semanal_dia INTEGER DEFAULT 1, informe_semanal_hora TEXT DEFAULT "07:00", informe_lecturas_whatsapp_habilitado INTEGER DEFAULT 0, informe_lecturas_hora TEXT DEFAULT "08:00")');
        $this->database->query('CREATE TABLE notificaciones (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER NULL, tipo_evento TEXT, estado TEXT, created_at TEXT, updated_at TEXT NULL)');
        $this->database->query('CREATE TABLE notificacion_entregas (id INTEGER PRIMARY KEY, notificacion_id INTEGER, canal TEXT, estado TEXT, enviada_en TEXT NULL, updated_at TEXT NULL)');
        $this->database->query('CREATE TABLE notificacion_whatsapp_entregas (id INTEGER PRIMARY KEY, empresa_id INTEGER, equipo_id INTEGER NULL, tipo_evento TEXT, estado TEXT, enviada_en TEXT NULL, updated_at TEXT NULL, created_at TEXT)');
        $this->database->query('CREATE TABLE notificacion_empresa_entregas (id INTEGER PRIMARY KEY, empresa_id INTEGER, tipo_evento TEXT, estado TEXT, enviada_en TEXT NULL, updated_at TEXT NULL, created_at TEXT)');
        $this->database->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER)');
        $this->database->query('CREATE TABLE integraciones_telemetria (id INTEGER PRIMARY KEY, empresa_id INTEGER, activo INTEGER, ultimo_ok_en TEXT NULL, consecutivos_fallidos INTEGER DEFAULT 0)');

        $this->database->table('empresas')->insert([
            'id' => 5,
            'deleted_at' => null,
            'informe_diario_habilitado' => 1,
            'informe_diario_hora' => '07:30',
        ]);
        $this->database->table('empresas')->insert(['id' => 6, 'deleted_at' => null]);

        $this->database->table('equipos')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'sucursal_id' => 9],
            ['id' => 11, 'empresa_id' => 5, 'sucursal_id' => 8],
        ]);

        $this->database->table('notificaciones')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'sucursal_id' => 9, 'tipo_evento' => 'preventivo.vencido', 'estado' => 'PENDIENTE', 'created_at' => '2026-11-05 06:00:00'],
            ['id' => 2, 'empresa_id' => 5, 'sucursal_id' => 8, 'tipo_evento' => 'preventivo.vencido', 'estado' => 'PENDIENTE', 'created_at' => '2026-11-05 06:00:00'],
            ['id' => 3, 'empresa_id' => 6, 'sucursal_id' => 4, 'tipo_evento' => 'preventivo.vencido', 'estado' => 'PENDIENTE', 'created_at' => '2026-11-05 06:00:00'],
        ]);

        $this->database->table('notificacion_entregas')->insert([
            'id' => 1, 'notificacion_id' => 1, 'canal' => 'EMAIL', 'estado' => 'FALLIDA', 'updated_at' => '2026-11-05 06:05:00',
        ]);

        $this->database->table('notificacion_whatsapp_entregas')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'equipo_id' => 10, 'tipo_evento' => 'equipo.resumen_vencimientos', 'estado' => 'PENDIENTE', 'enviada_en' => null, 'updated_at' => null, 'created_at' => '2026-11-05 06:10:00'],
            ['id' => 2, 'empresa_id' => 5, 'equipo_id' => 11, 'tipo_evento' => 'equipo.resumen_vencimientos', 'estado' => 'PENDIENTE', 'enviada_en' => null, 'updated_at' => null, 'created_at' => '2026-11-05 06:11:00'],
            ['id' => 3, 'empresa_id' => 5, 'equipo_id' => null, 'tipo_evento' => 'informe.lecturas.gerencia', 'estado' => 'ACEPTADA', 'enviada_en' => '2026-11-05 08:00:00', 'updated_at' => null, 'created_at' => '2026-11-05 08:00:00'],
            ['id' => 4, 'empresa_id' => 6, 'equipo_id' => 20, 'tipo_evento' => 'equipo.resumen_vencimientos', 'estado' => 'FALLIDA', 'enviada_en' => null, 'updated_at' => null, 'created_at' => '2026-11-05 06:12:00'],
        ]);

        $this->database->table('notificacion_empresa_entregas')->insert([
            'id' => 1, 'empresa_id' => 5, 'tipo_evento' => 'informe.gerencial.diario', 'estado' => 'PENDIENTE', 'created_at' => '2026-11-05 07:30:00',
        ]);

        $this->database->table('integraciones_telemetria')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'activo' => 1, 'ultimo_ok_en' => '2026-11-05 06:45:00', 'consecutivos_fallidos' => 1],
            ['id' => 2, 'empresa_id' => 6, 'activo' => 1, 'ultimo_ok_en' => '2026-11-05 06:45:00', 'consecutivos_fallidos' => 0],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testBuildsTenantEvidenceWithoutGlobalExecutionRows(): void
    {
        $overview = (new CodeIgniterAutomationOverviewReader($this->database))->forCompany(5, null);

        $preventive = $this->process($overview, 'preventive-alerts');
        self::assertSame(4, $preventive['pending']);
        self::assertSame(1, $preventive['failed']);
        self::assertSame('2026-11-05 06:11:00', $preventive['lastRunAt']);
        self::assertSame('CON_FALLOS', $preventive['lastOutcome']);

        $management = $this->process($overview, 'management-reports');
        self::assertTrue($management['enabled']);
        self::assertSame('Diario 07:30', $management['schedule']);
        self::assertSame(1, $management['pending']);
        self::assertSame(1, $management['sent']);
        self::assertSame('2026-11-05 08:00:00', $management['lastRunAt']);

        $telemetry = $this->process($overview, 'telemetry-sync');
        self::assertTrue($telemetry['enabled']);
        self::assertSame(1, $telemetry['failed']);
        self::assertSame('CON_FALLOS', $telemetry['lastOutcome']);
        self::assertSame('2026-11-05 06:45:00', $telemetry['lastRunAt']);

        self::assertSame(1, $overview['deliveries']['email']['pending']);
        self::assertSame(1, $overview['deliveries']['email']['failed']);
        self::assertSame(2, $overview['deliveries']['whatsapp']['pending']);
        self::assertSame(1, $overview['deliveries']['whatsapp']['sent']);
    }

    public function testRestrictedBranchOnlySeesAttributableEvidence(): void
    {
        $overview = (new CodeIgniterAutomationOverviewReader($this->database))->forCompany(5, [9]);

        $preventive = $this->process($overview, 'preventive-alerts');
        self::assertSame(2, $preventive['pending'], 'Sólo la notificación y el WhatsApp de la sucursal 9.');
        self::assertSame(1, $preventive['failed']);

        $management = $this->process($overview, 'management-reports');
        self::assertSame(0, $management['pending'], 'El informe gerencial es a nivel empresa y no se atribuye a la sucursal 9.');
        self::assertSame(0, $management['sent']);

        $telemetry = $this->process($overview, 'telemetry-sync');
        self::assertNull($telemetry['enabled']);
        self::assertNull($telemetry['lastRunAt']);

        self::assertSame(1, $overview['deliveries']['whatsapp']['pending']);
        self::assertSame(0, $overview['deliveries']['whatsapp']['sent']);
        self::assertNotEmpty(array_filter(
            $overview['limitations'],
            static fn (string $limitation): bool => str_contains($limitation, 'nivel empresa'),
        ));
    }

    public function testAnotherCompanyNeverAppears(): void
    {
        $overview = (new CodeIgniterAutomationOverviewReader($this->database))->forCompany(5, null);

        foreach ($overview['processes'] as $process) {
            self::assertNotSame('2026-11-05 06:12:00', $process['lastRunAt']);
        }
        self::assertSame(2, $overview['deliveries']['whatsapp']['pending']);
    }

    public function testUnavailableTablesAreReportedAsLimitationsInsteadOfErrors(): void
    {
        $database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);

        $overview = (new CodeIgniterAutomationOverviewReader($database))->forCompany(5, null);

        self::assertNotEmpty($overview['limitations']);
        self::assertCount(4, $overview['processes']);
        foreach ($overview['processes'] as $process) {
            self::assertNull($process['lastRunAt']);
            self::assertNull($process['lastOutcome']);
        }
        $database->close();
    }

    /** @param array{processes:list<array<string,mixed>>} $overview */
    private function process(array $overview, string $key): array
    {
        foreach ($overview['processes'] as $process) {
            if ($process['key'] === $key) {
                return $process;
            }
        }

        self::fail('Falta el proceso ' . $key);
    }
}
