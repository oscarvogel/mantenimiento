<?php

declare(strict_types=1);

use App\Application\Notifications\Port\GlobalNotificationSettingsStore;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\WhatsAppNotificationGateway;
use App\Domain\Notifications\NotifiableEvent;
use App\Domain\Notifications\NotificationSeverity;
use App\Infrastructure\Notifications\CodeIgniterDriverNotificationEligibilityReader;
use App\Infrastructure\Notifications\CodeIgniterWhatsAppNotificationDeliveryQueue;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * Regresión de #594: el resumen diario de vencimientos al chofer debe generarse
 * con el predicado único y sin errores de tipo en el cálculo de días.
 */
final class WhatsAppExpirationDigestScheduleTest extends TestCase
{
    private BaseConnection $db;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La prueba del resumen requiere sqlite3.');
        }

        $this->db = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
        ], false);
        $this->createSchema();
        $this->seed();
    }

    public function testItQueuesTheExpirationDigestForTheEligibleDriver(): void
    {
        $queue = $this->queue();

        $queue->scheduleDriverForEvent(new NotifiableEvent(
            5,
            null,
            'equipo.vencimiento_proximo',
            NotificationSeverity::WARNING,
            'Vencimiento próximo: CAM-01',
            'VTV vence en 15 días',
            'equipo',
            '10',
            'vencimiento:10:vtv',
            '/mantenimiento/equipos/10',
            new DateTimeImmutable('2026-11-27 10:00:00'),
        ));

        $rows = $this->db->table('notificacion_whatsapp_entregas')->get()->getResultArray();
        self::assertCount(1, $rows);
        self::assertSame('equipo.resumen_vencimientos', $rows[0]['tipo_evento']);
        self::assertSame('PENDIENTE', $rows[0]['estado']);
        self::assertSame(55, (int) $rows[0]['empleado_id']);
        self::assertStringContainsString('CAM-01', $rows[0]['mensaje']);
    }

    public function testItDoesNotQueueForAnIneligibleDriver(): void
    {
        $this->db->table('employee_equipment_assignments')->where('id', 1)->update([
            'fecha_hasta' => '2026-11-26',
        ]);

        $this->queue()->scheduleDriverForEvent(new NotifiableEvent(
            5,
            null,
            'equipo.vencimiento_proximo',
            NotificationSeverity::WARNING,
            'Vencimiento próximo: CAM-01',
            'VTV vence en 15 días',
            'equipo',
            '10',
            'vencimiento:10:vtv',
            '/mantenimiento/equipos/10',
            new DateTimeImmutable('2026-11-27 10:00:00'),
        ));

        self::assertCount(0, $this->db->table('notificacion_whatsapp_entregas')->get()->getResultArray());
    }

    private function queue(): CodeIgniterWhatsAppNotificationDeliveryQueue
    {
        return new CodeIgniterWhatsAppNotificationDeliveryQueue(
            new DigestScheduleClock(),
            new DigestScheduleGateway(),
            new DigestScheduleSettings(),
            $this->db,
            new CodeIgniterDriverNotificationEligibilityReader($this->db),
        );
    }

    private function createSchema(): void
    {
        $statements = <<<'SQL'
            CREATE TABLE empresas (id INTEGER PRIMARY KEY, razon_social TEXT, nombre_fantasia TEXT,
                estado INTEGER, deleted_at TEXT NULL, notificaciones_whatsapp_habilitadas INTEGER,
                whatsapp_instance_id TEXT NULL);
            CREATE TABLE empleados (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT,
                apellido TEXT, telefono TEXT NULL, activo INTEGER, deleted_at TEXT NULL);
            CREATE TABLE tipos_equipo (id INTEGER PRIMARY KEY, controla_km INTEGER);
            CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, tipo_equipo_id INTEGER,
                codigo TEXT, patente TEXT NULL, estado TEXT, deleted_at TEXT NULL);
            CREATE TABLE employee_equipment_assignments (id INTEGER PRIMARY KEY, empresa_id INTEGER,
                empleado_id INTEGER, equipo_id INTEGER, rol TEXT, fecha_desde TEXT, fecha_hasta TEXT NULL);
            CREATE TABLE tipos_vencimiento (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT,
                dias_aviso_previo INTEGER, deleted_at TEXT NULL);
            CREATE TABLE vencimientos (id INTEGER PRIMARY KEY, empresa_id INTEGER, equipo_id INTEGER,
                tipo_vencimiento_id INTEGER, fecha_vencimiento TEXT, sujeto_tipo TEXT,
                activo INTEGER, deleted_at TEXT NULL);
            CREATE TABLE notificacion_whatsapp_entregas (id INTEGER PRIMARY KEY, empresa_id INTEGER,
                equipo_id INTEGER NULL, empleado_id INTEGER NULL, usuario_id INTEGER NULL,
                tipo_evento TEXT, clave_entrega TEXT, external_ref TEXT, telefono TEXT NULL,
                instance_id TEXT NULL, mensaje TEXT, estado TEXT, gateway_message_id TEXT NULL,
                gateway_status TEXT NULL, intentos INTEGER DEFAULT 0, proximo_intento TEXT NULL,
                enviada_en TEXT NULL, ultimo_error TEXT NULL, motivo_cancelacion TEXT NULL,
                created_at TEXT, updated_at TEXT);
            SQL;

        foreach (array_filter(array_map('trim', explode(';', $statements))) as $statement) {
            $this->db->query($statement);
        }
    }

    private function seed(): void
    {
        $this->db->table('empresas')->insert([
            'id' => 5, 'razon_social' => 'Transportes SA', 'nombre_fantasia' => 'TSA',
            'estado' => 1, 'deleted_at' => null, 'notificaciones_whatsapp_habilitadas' => 1,
            'whatsapp_instance_id' => 'inst-5',
        ]);
        $this->db->table('empleados')->insert([
            'id' => 55, 'empresa_id' => 5, 'nombre' => 'Juan', 'apellido' => 'Pérez',
            'telefono' => '5493514449999', 'activo' => 1, 'deleted_at' => null,
        ]);
        $this->db->table('tipos_equipo')->insert(['id' => 1, 'controla_km' => 1]);
        $this->db->table('equipos')->insert([
            'id' => 10, 'empresa_id' => 5, 'tipo_equipo_id' => 1, 'codigo' => 'CAM-01',
            'patente' => 'AA123BB', 'estado' => 'ACTIVO', 'deleted_at' => null,
        ]);
        $this->db->table('employee_equipment_assignments')->insert([
            'id' => 1, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 10,
            'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null,
        ]);
        $this->db->table('tipos_vencimiento')->insert([
            'id' => 1, 'empresa_id' => 5, 'nombre' => 'VTV', 'dias_aviso_previo' => 30, 'deleted_at' => null,
        ]);
        $this->db->table('vencimientos')->insert([
            'id' => 1, 'empresa_id' => 5, 'equipo_id' => 10, 'tipo_vencimiento_id' => 1,
            'fecha_vencimiento' => '2026-12-12', 'sujeto_tipo' => 'EQUIPO', 'activo' => 1, 'deleted_at' => null,
        ]);
    }
}

final class DigestScheduleClock implements NotificationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-27 10:00:00');
    }
}

final class DigestScheduleGateway implements WhatsAppNotificationGateway
{
    public function sendText(string $phone, string $message, string $externalRef, ?string $actorId = null, ?string $actorName = null, ?string $instanceId = null): array
    {
        return ['messageId' => 'msg-1', 'status' => 'accepted'];
    }

    public function getMessageStatus(string $messageId, ?string $instanceId = null): array
    {
        return ['status' => 'accepted', 'providerMessageId' => $messageId, 'error' => null];
    }

    public function available(): bool
    {
        return true;
    }

    public function normalizePhone(string $phone): ?string
    {
        return trim($phone) === '' ? null : trim($phone);
    }
}

final class DigestScheduleSettings implements GlobalNotificationSettingsStore
{
    public function get(): array
    {
        return [
            'whatsapp_pilot_enabled' => false,
            'whatsapp_pilot_phone' => '',
            'whatsapp_instance_id' => 'inst-global',
        ];
    }

    public function save(array $settings, int $actorId): void
    {
    }
}
