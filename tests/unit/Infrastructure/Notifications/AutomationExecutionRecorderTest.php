<?php

declare(strict_types=1);

use App\Infrastructure\Notifications\CodeIgniterAutomationExecutionRecorder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\Notifications\FixedNotificationClock;

final class AutomationExecutionRecorderTest extends TestCase
{
    private BaseConnection $database;
    private CodeIgniterAutomationExecutionRecorder $recorder;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('Las etapas de automatización requieren sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE empresas (id INTEGER PRIMARY KEY)');
        $this->database->query('CREATE TABLE automatizacion_etapas (id INTEGER PRIMARY KEY AUTOINCREMENT, proceso TEXT, clave_ejecucion TEXT, empresa_id INTEGER NULL, estado TEXT, resumen TEXT NULL, error_seguro TEXT NULL, fecha_inicio TEXT, fecha_fin TEXT NULL, created_at TEXT)');
        $this->database->table('empresas')->insert(['id' => 5]);

        $this->recorder = new CodeIgniterAutomationExecutionRecorder(
            new FixedNotificationClock(new DateTimeImmutable('2026-11-05 07:00:00')),
            $this->database,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testRecordsGlobalAndTenantStagesWithCompletedFailedAndSkippedStates(): void
    {
        $global = $this->recorder->begin('notificaciones:ciclo:despacho', '2026-11-05-07-00', null);
        $this->recorder->finish($global, ['sent' => 3, 'failed' => 0]);

        $tenant = $this->recorder->begin('notificaciones:ciclo:informes_gerenciales', '2026-11-05-07-00', 5);
        $this->recorder->fail($tenant, 'Fallo con persona@example.com y +54 9 351 555-1234');

        $skipped = $this->recorder->begin('notificaciones:ciclo:telemetria', '2026-11-05-07-00', null);
        $this->recorder->skip($skipped, 'No corresponde en este minuto.');

        $rows = $this->database->table('automatizacion_etapas')->orderBy('id')->get()->getResultArray();
        self::assertCount(3, $rows);
        self::assertSame('COMPLETADA', $rows[0]['estado']);
        self::assertSame('{"sent":3,"failed":0}', $rows[0]['resumen']);
        self::assertNull($rows[0]['empresa_id']);
        self::assertSame('FALLIDA', $rows[1]['estado']);
        self::assertSame(5, (int) $rows[1]['empresa_id']);
        self::assertStringNotContainsString('persona@example.com', (string) $rows[1]['error_seguro']);
        self::assertStringNotContainsString('351 555-1234', (string) $rows[1]['error_seguro']);
        self::assertSame('OMITIDA', $rows[2]['estado']);
        self::assertSame('No corresponde en este minuto.', $rows[2]['error_seguro']);
        self::assertSame('2026-11-05 07:00:00', $rows[2]['fecha_fin']);
    }

    public function testRejectsInvalidTenantAndMissingMigration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->recorder->begin('notificaciones:ciclo:etapa', 'key', 0);
    }

    public function testMissingTableFailsWithAClearDeploymentError(): void
    {
        $database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $recorder = new CodeIgniterAutomationExecutionRecorder(
            new FixedNotificationClock(new DateTimeImmutable('2026-11-05 07:00:00')),
            $database,
        );

        $this->expectException(RuntimeException::class);
        try {
            $recorder->begin('notificaciones:ciclo:etapa', 'key', null);
        } finally {
            $database->close();
        }
    }
}
