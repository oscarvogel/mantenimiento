<?php

declare(strict_types=1);

use App\Infrastructure\Notifications\CodeIgniterNotificationProcessControl;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\Notifications\FixedNotificationClock;

final class NotificationProcessControlRecoveryTest extends TestCase
{
    private BaseConnection $database;
    private FixedNotificationClock $clock;
    private CodeIgniterNotificationProcessControl $control;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La recuperación del control de proceso requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE ejecuciones_programadas (id INTEGER PRIMARY KEY AUTOINCREMENT, proceso TEXT, clave_ejecucion TEXT, fecha_inicio TEXT, fecha_fin TEXT NULL, estado TEXT, resumen TEXT NULL, detalle_error TEXT NULL, UNIQUE (proceso, clave_ejecucion))');
        $this->database->query('CREATE TABLE bloqueos_proceso (proceso TEXT PRIMARY KEY, token TEXT, adquirido_en TEXT, expira_en TEXT)');
        $this->clock = new FixedNotificationClock(new DateTimeImmutable('2026-11-05 07:00:00'));
        $this->control = new CodeIgniterNotificationProcessControl($this->clock, $this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testRepeatedCompletedKeyIsNoOpAndLockIsReleased(): void
    {
        $token = $this->control->acquire('notificaciones:ciclo', 900);
        self::assertNotNull($token);
        $id = $this->control->start('notificaciones:ciclo', '2026-11-05-07-00');
        self::assertNotNull($id);
        $this->control->finish($id, ['stages' => 7]);
        $this->control->release('notificaciones:ciclo', $token);

        $token = $this->control->acquire('notificaciones:ciclo', 900);
        self::assertNotNull($token);
        self::assertNull($this->control->start('notificaciones:ciclo', '2026-11-05-07-00'));
        $this->control->release('notificaciones:ciclo', $token);
    }

    public function testConcurrentExecutionCannotStartWhileTheSameKeyIsInProgress(): void
    {
        $token = $this->control->acquire('notificaciones:ciclo', 900);
        self::assertNotNull($token);
        $this->control->start('notificaciones:ciclo', '2026-11-05-07-00');

        $this->expectException(RuntimeException::class);

        try {
            $this->control->start('notificaciones:ciclo', '2026-11-05-07-00');
        } finally {
            $this->control->release('notificaciones:ciclo', $token);
        }
    }

    public function testExpiredLockWithAnOldInProgressExecutionIsRecovered(): void
    {
        $this->database->table('ejecuciones_programadas')->insert([
            'proceso' => 'notificaciones:ciclo',
            'clave_ejecucion' => '2026-11-05-06-00',
            'fecha_inicio' => '2026-11-05 06:00:00',
            'estado' => 'EN_PROCESO',
        ]);

        $token = $this->control->acquire('notificaciones:ciclo', 900);
        self::assertNotNull($token);
        $id = $this->control->start('notificaciones:ciclo', '2026-11-05-06-00');

        self::assertNotNull($id);
        $row = $this->database->table('ejecuciones_programadas')->where('id', $id)->get()->getRowArray();
        self::assertSame('EN_PROCESO', $row['estado']);
        self::assertSame('2026-11-05 07:00:00', $row['fecha_inicio']);

        $this->control->release('notificaciones:ciclo', $token);
    }

    public function testFailureStoresASafeErrorAndFinishClearsIt(): void
    {
        $token = $this->control->acquire('notificaciones:despachar', 900);
        self::assertNotNull($token);
        $id = (int) $this->control->start('notificaciones:despachar', '2026-11-05-07-00');
        $this->control->fail($id, 'No se pudo enviar a persona@example.com al +54 9 351 555-1234 con token=abc123');

        $row = $this->database->table('ejecuciones_programadas')->where('id', $id)->get()->getRowArray();
        self::assertSame('FALLIDA', $row['estado']);
        self::assertStringNotContainsString('persona@example.com', (string) $row['detalle_error']);
        self::assertStringNotContainsString('351 555-1234', (string) $row['detalle_error']);
        self::assertStringNotContainsString('abc123', (string) $row['detalle_error']);
        self::assertStringContainsString('[correo]', (string) $row['detalle_error']);

        $this->control->finish($id, ['failed' => 1]);
        $row = $this->database->table('ejecuciones_programadas')->where('id', $id)->get()->getRowArray();
        self::assertNull($row['detalle_error']);
        $this->control->release('notificaciones:despachar', $token);
    }
}
