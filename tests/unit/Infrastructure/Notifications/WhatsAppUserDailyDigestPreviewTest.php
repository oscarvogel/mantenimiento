<?php

declare(strict_types=1);

use App\Application\Notifications\Port\GlobalNotificationSettingsStore;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\WhatsAppNotificationGateway;
use App\Infrastructure\Notifications\CodeIgniterWhatsAppNotificationDeliveryQueue;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class WhatsAppUserDailyDigestPreviewTest extends TestCase
{
    private BaseConnection $db;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La vista previa de digest requiere sqlite3.');
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
        foreach (['lecturas_equipo', 'notificaciones', 'planes_mantenimiento', 'equipos', 'permisos', 'rol_permisos', 'usuario_roles', 'usuarios', 'empresas'] as $table) {
            $this->db->query("DROP TABLE IF EXISTS {$table}");
        }
        $this->db->resetDataCache();
        parent::tearDown();
    }

    public function testPreviewUsesCurrentPlanStateAndDoesNotRegularizeStoredNotices(): void
    {
        $this->insertCompanyAndUser();
        $this->insertPlan(501, 'RHB2H00', 491115, 526115);
        $this->insertPlan(502, 'AB499OK', 216000, 215000);
        $this->insertNotification(601, 501, 'Aviso viejo de RHB2H00');
        $this->insertNotification(602, 502, 'Mantenimiento vencido: AB499OK');

        $queue = new CodeIgniterWhatsAppNotificationDeliveryQueue(
            new DigestPreviewTestClock(),
            new DigestPreviewTestGateway(),
            new DigestPreviewTestSettings(),
            $this->db,
        );
        $previews = $queue->previewUserDailyDigests(1);

        self::assertCount(1, $previews);
        self::assertSame(1, $previews[0]['itemCount']);
        self::assertStringContainsString('Mantenimiento vencido: AB499OK', $previews[0]['message']);
        self::assertStringNotContainsString('Aviso viejo de RHB2H00', $previews[0]['message']);
        self::assertSame('PENDIENTE', $this->notificationState(601));
        self::assertSame('PENDIENTE', $this->notificationState(602));
    }

    private function insertCompanyAndUser(): void
    {
        $this->db->table('empresas')->insert([
            'id' => 1, 'razon_social' => 'Transportes Austral', 'nombre_fantasia' => 'TSA',
            'estado' => 1, 'deleted_at' => null, 'notificaciones_whatsapp_habilitadas' => 1,
        ]);
        $this->db->table('usuarios')->insert([
            'id' => 8, 'empresa_id' => 1, 'nombre' => 'Carlos Gomez', 'telefono' => '+5491100000000',
            'activo' => 1, 'deleted_at' => null,
        ]);
        $this->db->table('usuario_roles')->insert(['usuario_id' => 8, 'rol_id' => 1]);
        $this->db->table('rol_permisos')->insert(['rol_id' => 1, 'permiso_id' => 1]);
        $this->db->table('permisos')->insert(['id' => 1, 'clave' => 'notificaciones.ver']);
    }

    private function insertPlan(int $id, string $code, int $km, int $nextKm): void
    {
        $this->db->table('equipos')->insert([
            'id' => $id, 'empresa_id' => 1, 'codigo' => $code, 'km_actual' => $km,
            'horas_actuales' => null, 'deleted_at' => null,
        ]);
        $this->db->table('planes_mantenimiento')->insert([
            'id' => $id, 'empresa_id' => 1, 'equipo_id' => $id, 'tipo_servicio_id' => 1,
            'intervalo_km' => 35000, 'intervalo_horas' => null, 'intervalo_dias' => null,
            'anticipacion_km' => 2000, 'anticipacion_horas' => null, 'anticipacion_dias' => null,
            'base_km' => $nextKm - 35000, 'base_horas' => null, 'base_fecha' => null,
            'proximo_km' => $nextKm, 'proximas_horas' => null, 'proxima_fecha' => null,
            'prioridad' => 'MEDIA', 'activo' => 1, 'deleted_at' => null, 'observaciones' => null,
        ]);
    }

    private function insertNotification(int $id, int $planId, string $title): void
    {
        $this->db->table('notificaciones')->insert([
            'id' => $id, 'empresa_id' => 1, 'usuario_id' => 8, 'tipo_evento' => 'preventivo.vencido',
            'severidad' => 'WARNING', 'titulo' => $title, 'resumen' => 'Servicio Motor · criterios: KILOMETRAJE',
            'entidad_tipo' => 'plan_mantenimiento', 'entidad_id' => (string) $planId,
            'estado' => 'PENDIENTE', 'clave_evento' => 'test:' . $id,
        ]);
    }

    private function notificationState(int $id): string
    {
        return (string) $this->db->table('notificaciones')->select('estado')->where('id', $id)->get()->getRowArray()['estado'];
    }

    private function createSchema(): void
    {
        $this->db->query('CREATE TABLE empresas (id INTEGER PRIMARY KEY, razon_social TEXT, nombre_fantasia TEXT, estado INTEGER, deleted_at TEXT, notificaciones_whatsapp_habilitadas INTEGER)');
        $this->db->query('CREATE TABLE usuarios (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, telefono TEXT, activo INTEGER, deleted_at TEXT)');
        $this->db->query('CREATE TABLE usuario_roles (usuario_id INTEGER, rol_id INTEGER)');
        $this->db->query('CREATE TABLE rol_permisos (rol_id INTEGER, permiso_id INTEGER)');
        $this->db->query('CREATE TABLE permisos (id INTEGER PRIMARY KEY, clave TEXT)');
        $this->db->query('CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, codigo TEXT, km_actual INTEGER, horas_actuales NUMERIC, deleted_at TEXT)');
        $this->db->query('CREATE TABLE planes_mantenimiento (
            id INTEGER PRIMARY KEY, empresa_id INTEGER, equipo_id INTEGER, tipo_servicio_id INTEGER,
            intervalo_km INTEGER, intervalo_horas NUMERIC, intervalo_dias INTEGER,
            anticipacion_km INTEGER, anticipacion_horas NUMERIC, anticipacion_dias INTEGER,
            base_km INTEGER, base_horas NUMERIC, base_fecha TEXT, proximo_km INTEGER,
            proximas_horas NUMERIC, proxima_fecha TEXT, prioridad TEXT, activo INTEGER,
            deleted_at TEXT, observaciones TEXT
        )');
        $this->db->query('CREATE TABLE notificaciones (
            id INTEGER PRIMARY KEY, empresa_id INTEGER, usuario_id INTEGER, tipo_evento TEXT,
            severidad TEXT, titulo TEXT, resumen TEXT, entidad_tipo TEXT, entidad_id TEXT,
            estado TEXT, clave_evento TEXT, leida_en TEXT NULL, updated_at TEXT NULL
        )');
        $this->db->query('CREATE TABLE lecturas_equipo (
            id INTEGER PRIMARY KEY, empresa_id INTEGER, equipo_id INTEGER, anulada INTEGER,
            kilometraje INTEGER, fecha_lectura TEXT
        )');
    }
}

final class DigestPreviewTestClock implements NotificationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-15 18:00:00');
    }
}

final class DigestPreviewTestGateway implements WhatsAppNotificationGateway
{
    public function sendText(string $phone, string $message, string $externalRef, ?string $actorId = null, ?string $actorName = null, ?string $instanceId = null): array { return ['messageId' => 'test', 'status' => 'sent']; }
    public function getMessageStatus(string $messageId, ?string $instanceId = null): array { return ['status' => 'sent', 'providerMessageId' => $messageId, 'error' => null]; }
    public function available(): bool { return true; }
    public function normalizePhone(string $phone): ?string { return preg_match('/^\+?[0-9]{10,15}$/', $phone) === 1 ? $phone : null; }
}

final class DigestPreviewTestSettings implements GlobalNotificationSettingsStore
{
    public function get(): array { return ['whatsapp_pilot_enabled' => false]; }
    public function save(array $settings, int $actorId): void { }
}
