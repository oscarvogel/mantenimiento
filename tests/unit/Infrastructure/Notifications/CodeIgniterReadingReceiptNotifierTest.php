<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notifications;

use App\Application\Notifications\Port\GlobalNotificationSettingsStore;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\WhatsAppNotificationGateway;
use App\Infrastructure\Notifications\CodeIgniterReadingReceiptNotifier;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CodeIgniterReadingReceiptNotifierTest extends TestCase
{
    private BaseConnection $db;

    private ReceiptWhatsAppGateway $gateway;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La prueba del acuse requiere sqlite3.');
        }

        $this->db = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
        ], false);

        $this->gateway = new ReceiptWhatsAppGateway();

        $this->createSchema();
        $this->seed();
    }

    public function testItQueuesAReceiptForTheCurrentDriver(): void
    {
        $this->notifier()->notify(5, 10, 123);

        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries);

        $delivery = $deliveries[0];
        self::assertSame('equipo.lectura_registrada', $delivery['tipo_evento']);
        self::assertSame('lectura.registrada:empresa:5:equipo:10:lectura:123', $delivery['clave_entrega']);
        self::assertSame('PENDIENTE', $delivery['estado']);
        self::assertSame('5493514449999', $delivery['telefono']);
        self::assertSame(55, (int) $delivery['empleado_id']);
        self::assertSame('inst-5', $delivery['instance_id']);
        self::assertStringContainsString('494.497 km', $delivery['mensaje']);
        self::assertStringContainsString('L-000123', $delivery['mensaje']);
    }

    public function testItIsIdempotentPerReading(): void
    {
        $notifier = $this->notifier();

        $notifier->notify(5, 10, 123);
        $notifier->notify(5, 10, 123);

        self::assertCount(1, $this->deliveries());
    }

    public function testPilotModeRedirectsToThePilotPhone(): void
    {
        $this->notifier([
            'whatsapp_pilot_enabled' => true,
            'whatsapp_pilot_phone' => '5493510000000',
        ])->notify(5, 10, 123);

        $delivery = $this->deliveries()[0];
        self::assertSame('5493510000000', $delivery['telefono']);
        self::assertNotSame('5493514449999', $delivery['telefono']);
        self::assertStringContainsString('PRUEBA CONTROLADA', $delivery['mensaje']);
    }

    public function testEquipmentWithoutDriverQueuesAnOmittedReceipt(): void
    {
        $this->insertReading(124, 11, 180000);

        $this->notifier()->notify(5, 11, 124);

        $delivery = $this->deliveries()[0];
        self::assertSame('OMITIDA', $delivery['estado']);
        self::assertStringContainsString('chofer', (string) $delivery['ultimo_error']);
    }

    public function testInvalidDriverPhoneQueuesAnOmittedReceipt(): void
    {
        $this->insertReading(125, 12, 190000);

        $this->notifier()->notify(5, 12, 125);

        $delivery = $this->deliveries()[0];
        self::assertSame('OMITIDA', $delivery['estado']);
        self::assertStringContainsString('celular válido', (string) $delivery['ultimo_error']);
    }

    public function testCompanyWithWhatsAppDisabledDoesNotQueueAnything(): void
    {
        $this->db->table('empresas')->where('id', 5)->update(['notificaciones_whatsapp_habilitadas' => 0]);

        $this->notifier()->notify(5, 10, 123);

        self::assertSame([], $this->deliveries());
    }

    public function testAnAnnulledReadingDoesNotQueueAnything(): void
    {
        $this->db->table('lecturas_equipo')->where('id', 123)->update(['anulada' => 1]);

        $this->notifier()->notify(5, 10, 123);

        self::assertSame([], $this->deliveries());
    }

    public function testUnavailableGatewayDoesNotQueueAnything(): void
    {
        $this->notifier([], false)->notify(5, 10, 123);

        self::assertSame([], $this->deliveries());
    }

    public function testARelevantAiDiscrepancyAddsTheReviewWording(): void
    {
        $this->insertReading(126, 10, 100000);
        $this->db->table('lecturas_equipo_evidencias')->insert([
            'id' => 2,
            'empresa_id' => 5,
            'lectura_id' => 126,
            'metodo_carga' => 'FOTO_IA_CORREGIDA',
            'km_detectado_ia' => 200000,
            'confianza_ia' => 0.95,
        ]);

        $this->notifier()->notify(5, 10, 126);

        self::assertStringContainsString('mantenimiento lo va a revisar', $this->deliveries()[0]['mensaje']);
    }

    public function testAnHoursOnlyReadingShowsTheHours(): void
    {
        $this->insertReading(127, 10, null, 1234.5);
        $this->db->table('lecturas_equipo_evidencias')->insert([
            'id' => 3,
            'empresa_id' => 5,
            'lectura_id' => 127,
            'metodo_carga' => 'FOTO_MANUAL',
            'km_detectado_ia' => null,
            'confianza_ia' => null,
        ]);

        $this->notifier()->notify(5, 10, 127);

        $message = $this->deliveries()[0]['mensaje'];
        self::assertStringContainsString('Horómetro: *1.234,5 h*', $message);
        self::assertStringNotContainsString('Kilometraje:', $message);
    }

    private function notifier(array $settings = [], bool $gatewayAvailable = true): CodeIgniterReadingReceiptNotifier
    {
        $this->gateway->available = $gatewayAvailable;

        return new CodeIgniterReadingReceiptNotifier(
            $this->db,
            $this->gateway,
            new ReceiptSettingsStore($settings + [
                'whatsapp_pilot_enabled' => false,
                'whatsapp_pilot_phone' => '',
                'whatsapp_instance_id' => 'inst-global',
            ]),
            new ReceiptClock(new DateTimeImmutable('2026-11-05 09:00:00')),
        );
    }

    /** @return list<array<string,mixed>> */
    private function deliveries(): array
    {
        return $this->db->table('notificacion_whatsapp_entregas')->get()->getResultArray();
    }

    private function insertReading(int $id, int $equipmentId, ?int $kilometers, ?float $hours = null): void
    {
        $this->db->table('lecturas_equipo')->insert([
            'id' => $id,
            'empresa_id' => 5,
            'sucursal_id' => 7,
            'equipo_id' => $equipmentId,
            'fecha_lectura' => '2026-11-05 08:30:00',
            'kilometraje' => $kilometers,
            'horometro' => $hours,
            'anulada' => 0,
        ]);
    }

    private function createSchema(): void
    {
        $statements = <<<'SQL'
            CREATE TABLE empresas (id INTEGER PRIMARY KEY, razon_social TEXT, nombre_fantasia TEXT,
                estado INTEGER, deleted_at TEXT NULL, notificaciones_whatsapp_habilitadas INTEGER,
                whatsapp_instance_id TEXT, idioma_notificaciones TEXT);
            CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, estado INTEGER,
                deleted_at TEXT NULL, idioma_notificaciones TEXT);
            CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER,
                codigo TEXT, patente TEXT NULL, estado TEXT, deleted_at TEXT NULL);
            CREATE TABLE empleados (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, apellido TEXT,
                telefono TEXT NULL, activo INTEGER, deleted_at TEXT NULL);
            CREATE TABLE employee_equipment_assignments (id INTEGER PRIMARY KEY, empresa_id INTEGER,
                empleado_id INTEGER, equipo_id INTEGER, rol TEXT, fecha_desde TEXT, fecha_hasta TEXT NULL);
            CREATE TABLE lecturas_equipo (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER,
                equipo_id INTEGER, fecha_lectura TEXT, kilometraje INTEGER NULL, horometro REAL NULL,
                anulada INTEGER);
            CREATE TABLE lecturas_equipo_evidencias (id INTEGER PRIMARY KEY, empresa_id INTEGER,
                lectura_id INTEGER, metodo_carga TEXT, km_detectado_ia INTEGER NULL, confianza_ia REAL NULL);
            CREATE TABLE notificacion_whatsapp_entregas (id INTEGER PRIMARY KEY, empresa_id INTEGER,
                equipo_id INTEGER, empleado_id INTEGER NULL, usuario_id INTEGER NULL, tipo_evento TEXT,
                clave_entrega TEXT, external_ref TEXT, telefono TEXT NULL, instance_id TEXT NULL,
                provider_message_id TEXT NULL, mensaje TEXT, estado TEXT, gateway_message_id TEXT NULL,
                gateway_status TEXT NULL, intentos INTEGER, proximo_intento TEXT NULL, enviada_en TEXT NULL,
                ultimo_error TEXT NULL, created_at TEXT, updated_at TEXT);
            CREATE UNIQUE INDEX ux_clave_entrega ON notificacion_whatsapp_entregas (clave_entrega);
            SQL;

        foreach (array_filter(array_map('trim', explode(';', $statements))) as $statement) {
            $this->db->query($statement);
        }
    }

    private function seed(): void
    {
        $this->db->table('empresas')->insertBatch([
            ['id' => 5, 'razon_social' => 'Transportes SA', 'nombre_fantasia' => 'TSA', 'estado' => 1, 'deleted_at' => null, 'notificaciones_whatsapp_habilitadas' => 1, 'whatsapp_instance_id' => 'inst-5', 'idioma_notificaciones' => 'ES'],
        ]);
        $this->db->table('sucursales')->insert([
            'id' => 7, 'empresa_id' => 5, 'nombre' => 'Central', 'estado' => 1, 'deleted_at' => null, 'idioma_notificaciones' => '',
        ]);
        $this->db->table('equipos')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'sucursal_id' => 7, 'codigo' => 'CAM-01', 'patente' => 'AA123BB', 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 11, 'empresa_id' => 5, 'sucursal_id' => 7, 'codigo' => 'CAM-SIN-CHOFER', 'patente' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 12, 'empresa_id' => 5, 'sucursal_id' => 7, 'codigo' => 'CAM-SIN-TEL', 'patente' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
        ]);
        $this->db->table('empleados')->insertBatch([
            ['id' => 55, 'empresa_id' => 5, 'nombre' => 'Juan', 'apellido' => 'Pérez', 'telefono' => '5493514449999', 'activo' => 1, 'deleted_at' => null],
            ['id' => 56, 'empresa_id' => 5, 'nombre' => 'Ana', 'apellido' => 'García', 'telefono' => null, 'activo' => 1, 'deleted_at' => null],
        ]);
        $this->db->table('employee_equipment_assignments')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 10, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 2, 'empresa_id' => 5, 'empleado_id' => 56, 'equipo_id' => 12, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
        ]);
        $this->db->table('lecturas_equipo')->insert([
            'id' => 123, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 10,
            'fecha_lectura' => '2026-11-05 08:14:00', 'kilometraje' => 494497, 'horometro' => null, 'anulada' => 0,
        ]);
        $this->db->table('lecturas_equipo_evidencias')->insert([
            'id' => 1, 'empresa_id' => 5, 'lectura_id' => 123, 'metodo_carga' => 'FOTO_IA_CONFIRMADA',
            'km_detectado_ia' => 494497, 'confianza_ia' => 0.95,
        ]);
    }
}

final class ReceiptWhatsAppGateway implements WhatsAppNotificationGateway
{
    public bool $available = true;

    public function available(): bool
    {
        return $this->available;
    }

    public function normalizePhone(string $phone): ?string
    {
        $raw = trim($phone);
        if ($raw === '' || preg_match('/^[0-9]+$/', $raw) !== 1) {
            return null;
        }
        if (str_starts_with($raw, '54')) {
            return preg_match('/^549[0-9]{10}$/', $raw) === 1 ? $raw : null;
        }

        return preg_match('/^[1-9][0-9]{9,14}$/', $raw) === 1 ? $raw : null;
    }

    public function sendText(
        string $phone,
        string $message,
        string $externalRef,
        ?string $actorId = null,
        ?string $actorName = null,
        ?string $instanceId = null,
    ): array {
        return ['messageId' => 'wamid.test', 'status' => 'accepted'];
    }

    public function getMessageStatus(string $messageId, ?string $instanceId = null): array
    {
        return ['status' => 'delivered', 'providerMessageId' => $messageId, 'error' => null];
    }
}

final class ReceiptSettingsStore implements GlobalNotificationSettingsStore
{
    /** @param array<string,mixed> $values */
    public function __construct(private array $values)
    {
    }

    public function get(): array
    {
        return $this->values;
    }

    public function save(array $settings, int $actorId): void
    {
        $this->values = $settings;
    }
}

final class ReceiptClock implements NotificationClock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
