<?php

declare(strict_types=1);

use App\Infrastructure\Notifications\PreventiveNotificationRevalidator;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class PreventiveNotificationRevalidatorTest extends TestCase
{
    private BaseConnection $db;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La revalidacion preventiva requiere sqlite3.');
        }

        $this->db = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
        ], false);
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach (['notificaciones', 'planes_mantenimiento', 'equipos'] as $table) {
            $this->db->query("DROP TABLE IF EXISTS {$table}");
        }
        $this->db->resetDataCache();
        parent::tearDown();
    }

    public function testRegularizesOverdueNoticeWhenPlanIsNowUpToDate(): void
    {
        $this->insertPlanAndNotice(501, 'RHB2H00', 491115, 526115);
        $this->insertPlanAndNotice(502, 'OTRO', 216000, 215000);

        $revalidator = new PreventiveNotificationRevalidator($this->db);
        self::assertSame([501], $revalidator->notificationIdsToRegularize(
            1,
            8,
            new DateTimeImmutable('2026-10-15 18:00:00'),
        ));

        $resolved = $revalidator->revalidate(1, 8, new DateTimeImmutable('2026-10-15 18:00:00'));

        self::assertSame(1, $resolved);
        self::assertSame('REGULARIZADA', $this->state(501));
        self::assertSame('PENDIENTE', $this->state(502));
    }

    private function insertPlanAndNotice(int $planId, string $code, int $km, int $nextKm): void
    {
        $this->db->table('equipos')->insert([
            'id' => $planId,
            'empresa_id' => 1,
            'codigo' => $code,
            'km_actual' => $km,
            'horas_actuales' => null,
        ]);
        $this->db->table('planes_mantenimiento')->insert([
            'id' => $planId,
            'empresa_id' => 1,
            'equipo_id' => $planId,
            'tipo_servicio_id' => 1,
            'intervalo_km' => 35000,
            'intervalo_horas' => null,
            'intervalo_dias' => null,
            'anticipacion_km' => 2000,
            'anticipacion_horas' => null,
            'anticipacion_dias' => null,
            'base_km' => $nextKm - 35000,
            'base_horas' => null,
            'base_fecha' => null,
            'proximo_km' => $nextKm,
            'proximas_horas' => null,
            'proxima_fecha' => null,
            'prioridad' => 'MEDIA',
            'activo' => 1,
        ]);
        $this->db->table('notificaciones')->insert([
            'id' => $planId,
            'empresa_id' => 1,
            'usuario_id' => 8,
            'tipo_evento' => 'preventivo.vencido',
            'entidad_tipo' => 'plan_mantenimiento',
            'entidad_id' => (string) $planId,
            'estado' => 'PENDIENTE',
        ]);
    }

    private function state(int $id): string
    {
        return (string) $this->db->table('notificaciones')->select('estado')->where('id', $id)->get()->getRowArray()['estado'];
    }

    private function createSchema(): void
    {
        $this->db->query('CREATE TABLE equipos (
            id INTEGER PRIMARY KEY, empresa_id INTEGER NOT NULL, codigo TEXT NOT NULL,
            km_actual INTEGER NULL, horas_actuales NUMERIC NULL, deleted_at TEXT NULL
        )');
        $this->db->query('CREATE TABLE planes_mantenimiento (
            id INTEGER PRIMARY KEY, empresa_id INTEGER NOT NULL, equipo_id INTEGER NOT NULL,
            tipo_servicio_id INTEGER NOT NULL, intervalo_km INTEGER NULL, intervalo_horas NUMERIC NULL,
            intervalo_dias INTEGER NULL, anticipacion_km INTEGER NULL, anticipacion_horas NUMERIC NULL,
            anticipacion_dias INTEGER NULL, base_km INTEGER NULL, base_horas NUMERIC NULL,
            base_fecha TEXT NULL, proximo_km INTEGER NULL, proximas_horas NUMERIC NULL,
            proxima_fecha TEXT NULL, prioridad TEXT NOT NULL, activo INTEGER NOT NULL,
            deleted_at TEXT NULL, observaciones TEXT NULL
        )');
        $this->db->query('CREATE TABLE notificaciones (
            id INTEGER PRIMARY KEY, empresa_id INTEGER NOT NULL, usuario_id INTEGER NOT NULL,
            tipo_evento TEXT NOT NULL, entidad_tipo TEXT NOT NULL, entidad_id TEXT NOT NULL,
            estado TEXT NOT NULL, leida_en TEXT NULL, updated_at TEXT NULL
        )');
    }
}
