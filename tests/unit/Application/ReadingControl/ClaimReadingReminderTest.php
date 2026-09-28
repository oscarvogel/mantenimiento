<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\GlobalNotificationSettingsStore;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\WhatsAppNotificationDeliveryQueue;
use App\Application\Notifications\Port\WhatsAppNotificationGateway;
use App\Application\ReadingControl\ClaimReadingReminder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * Fakes de los puertos de notificaciones.
 *
 * Se usan dobles SOLO en los puertos. La consulta de destinatario, el
 * aislamiento por empresa, la trazabilidad y los cortes de la base se prueban
 * contra una base real, porque ahí es donde antes falló el hotfix.
 */
final class RecordingWhatsAppGateway implements WhatsAppNotificationGateway
{
    /** @var list<array{phone:string,message:string,externalRef:string,actorId:?string,instanceId:?string}> */
    public array $sent = [];

    public bool $available = true;

    public ?string $failWith = null;

    public string $messageId = 'wamid.test.1';

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
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        $this->sent[] = [
            'phone' => $phone,
            'message' => $message,
            'externalRef' => $externalRef,
            'actorId' => $actorId,
            'instanceId' => $instanceId,
        ];

        return ['messageId' => $this->messageId, 'status' => 'accepted'];
    }

    public function getMessageStatus(string $messageId, ?string $instanceId = null): array
    {
        return ['status' => 'delivered', 'providerMessageId' => $messageId, 'error' => null];
    }
}

final class RecordingDeliveryQueue implements WhatsAppNotificationDeliveryQueue
{
    /** @var list<array{deliveryId:int,messageId:string,status:string}> */
    public array $accepted = [];

    public function accepted(int $deliveryId, string $messageId, string $status): void
    {
        $this->accepted[] = ['deliveryId' => $deliveryId, 'messageId' => $messageId, 'status' => $status];
    }

    public function scheduleDriverForEvent(\App\Domain\Notifications\NotifiableEvent $event): void
    {
    }

    public function scheduleWeeklyReadingReminders(bool $force = false, ?string $testKey = null): int
    {
        return 0;
    }

    public function due(int $limit): array
    {
        return [];
    }

    public function awaitingConfirmation(int $limit): array
    {
        return [];
    }

    public function reconcileStatus(
        int $deliveryId,
        string $status,
        ?string $providerMessageId = null,
        ?string $error = null,
    ): void {
    }

    public function skipped(int $deliveryId, string $reason): void
    {
    }

    public function failed(int $deliveryId, string $error, bool $retryable): void
    {
    }
}

final class FakeNotificationClock implements NotificationClock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class FakeSettingsStore implements GlobalNotificationSettingsStore
{
    /** @param array<string, mixed> $values */
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

/**
 * Reclamo manual de lectura por WhatsApp.
 *
 * No se envía ningún WhatsApp real: el gateway es un doble y solo se verifica
 * a quién SE HABRÍA enviado y qué se habría persistido.
 */
final class ClaimReadingReminderTest extends TestCase
{
    private const NOW = '2026-09-28 10:00:00';

    private BaseConnection $db;

    private RecordingWhatsAppGateway $gateway;

    private RecordingDeliveryQueue $queue;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La prueba de reclamo requiere sqlite3.');
        }

        $this->db = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug'  => true,
        ], false);

        $this->gateway = new RecordingWhatsAppGateway();
        $this->queue = new RecordingDeliveryQueue();
        $this->createSchema();
        $this->seed();
    }

    private function createSchema(): void
    {
        $this->db->executescript(
            <<<'SQL'
            CREATE TABLE empresas (id INTEGER PRIMARY KEY, razon_social TEXT, nombre_fantasia TEXT,
                estado INTEGER, deleted_at TEXT NULL, notificaciones_whatsapp_habilitadas INTEGER,
                whatsapp_instance_id TEXT, idioma_notificaciones TEXT);
            CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, estado INTEGER,
                deleted_at TEXT NULL, idioma_notificaciones TEXT);
            CREATE TABLE tipos_equipo (id INTEGER PRIMARY KEY, nombre TEXT, controla_km INTEGER,
                controla_horas INTEGER, activo INTEGER);
            CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER,
                tipo_equipo_id INTEGER, codigo TEXT, patente TEXT NULL, chasis TEXT NULL, km_actual INTEGER NULL,
                estado TEXT, deleted_at TEXT NULL);
            CREATE TABLE empleados (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, apellido TEXT,
                telefono TEXT NULL, activo INTEGER, deleted_at TEXT NULL);
            CREATE TABLE employee_equipment_assignments (id INTEGER PRIMARY KEY, empresa_id INTEGER,
                empleado_id INTEGER, equipo_id INTEGER, rol TEXT, fecha_desde TEXT, fecha_hasta TEXT NULL);
            CREATE TABLE lecturas_equipo (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER,
                equipo_id INTEGER, fecha_lectura TEXT, kilometraje INTEGER NULL, horometro REAL NULL,
                origen TEXT, usuario_id INTEGER, anulada INTEGER);
            CREATE TABLE equipo_tokens_publicos (id INTEGER PRIMARY KEY, empresa_id INTEGER,
                equipo_id INTEGER, token_hash TEXT, activo INTEGER DEFAULT 1, created_by INTEGER NULL,
                created_at TEXT, revoked_by INTEGER NULL, revoked_at TEXT NULL);
            CREATE TABLE notificacion_whatsapp_entregas (id INTEGER PRIMARY KEY, empresa_id INTEGER,
                equipo_id INTEGER, empleado_id INTEGER, tipo_evento TEXT, clave_entrega TEXT,
                external_ref TEXT, telefono TEXT NULL, instance_id TEXT NULL, provider_message_id TEXT NULL,
                mensaje TEXT, estado TEXT, gateway_message_id TEXT NULL, gateway_status TEXT NULL,
                intentos INTEGER, proximo_intento TEXT NULL, enviada_en TEXT NULL, ultimo_error TEXT NULL,
                created_at TEXT, updated_at TEXT);
            CREATE UNIQUE INDEX ux_clave_entrega ON notificacion_whatsapp_entregas (clave_entrega);
            SQL
        );
    }

    private function seed(): void
    {
        $this->db->table('empresas')->insertBatch([
            ['id' => 5, 'razon_social' => 'Transportes SA', 'nombre_fantasia' => 'TSA', 'estado' => 1, 'deleted_at' => null, 'notificaciones_whatsapp_habilitadas' => 1, 'whatsapp_instance_id' => 'inst-5', 'idioma_notificaciones' => 'ES'],
            ['id' => 99, 'razon_social' => 'Otra SA', 'nombre_fantasia' => 'Otra', 'estado' => 1, 'deleted_at' => null, 'notificaciones_whatsapp_habilitadas' => 1, 'whatsapp_instance_id' => 'inst-99', 'idioma_notificaciones' => 'ES'],
        ]);
        $this->db->table('sucursales')->insertBatch([
            ['id' => 7, 'empresa_id' => 5, 'nombre' => 'Central', 'estado' => 1, 'deleted_at' => null, 'idioma_notificaciones' => ''],
            ['id' => 9, 'empresa_id' => 99, 'nombre' => 'Otra', 'estado' => 1, 'deleted_at' => null, 'idioma_notificaciones' => ''],
        ]);
        $this->db->table('tipos_equipo')->insert([
            ['id' => 1, 'nombre' => 'Camión', 'controla_km' => 1, 'controla_horas' => 0, 'activo' => 1],
        ]);
        $this->db->table('equipos')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 1, 'codigo' => 'CAM-01', 'patente' => 'AA123BB', 'chasis' => null, 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 11, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 1, 'codigo' => 'CAM-SIN-CHOFER', 'patente' => null, 'chasis' => null, 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 12, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 1, 'codigo' => 'CAM-SIN-TEL', 'patente' => null, 'chasis' => null, 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 13, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 1, 'codigo' => 'CAM-SIN-LECTURA', 'patente' => null, 'chasis' => null, 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 14, 'empresa_id' => 5, 'sucursal_id' => 7, 'tipo_equipo_id' => 1, 'codigo' => 'CAM-TEL-INVALIDO', 'patente' => null, 'chasis' => null, 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 90, 'empresa_id' => 99, 'sucursal_id' => 9, 'tipo_equipo_id' => 1, 'codigo' => 'OTRO-01', 'patente' => null, 'chasis' => null, 'km_actual' => null, 'estado' => 'ACTIVO', 'deleted_at' => null],
        ]);
        $this->db->table('empleados')->insertBatch([
            ['id' => 55, 'empresa_id' => 5, 'nombre' => 'Juan', 'apellido' => 'Pérez', 'telefono' => '5493514449999', 'activo' => 1, 'deleted_at' => null],
            ['id' => 56, 'empresa_id' => 5, 'nombre' => 'Ana', 'apellido' => 'García', 'telefono' => null, 'activo' => 1, 'deleted_at' => null],
            ['id' => 57, 'empresa_id' => 5, 'nombre' => 'Luis', 'apellido' => 'Torres', 'telefono' => '123', 'activo' => 1, 'deleted_at' => null],
            ['id' => 58, 'empresa_id' => 5, 'nombre' => 'Old', 'apellido' => 'Driver', 'telefono' => '5493511111111', 'activo' => 0, 'deleted_at' => null],
            ['id' => 95, 'empresa_id' => 99, 'nombre' => 'Otro', 'apellido' => 'Chofer', 'telefono' => '5493599999999', 'activo' => 1, 'deleted_at' => null],
        ]);
        $this->db->table('employee_equipment_assignments')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 10, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 2, 'empresa_id' => 5, 'empleado_id' => 56, 'equipo_id' => 12, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 3, 'empresa_id' => 5, 'empleado_id' => 57, 'equipo_id' => 14, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            // Asignación vencida: no debe contar como chofer vigente.
            ['id' => 4, 'empresa_id' => 5, 'empleado_id' => 58, 'equipo_id' => 11, 'rol' => 'CHOFER', 'fecha_desde' => '2025-01-01', 'fecha_hasta' => '2025-12-31'],
            ['id' => 95, 'empresa_id' => 99, 'empleado_id' => 95, 'equipo_id' => 90, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
        ]);
        $this->db->table('lecturas_equipo')->insertBatch([
            ['id' => 100, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 10, 'fecha_lectura' => '2026-09-20 08:00:00', 'kilometraje' => 180000, 'horometro' => null, 'origen' => 'MANUAL', 'usuario_id' => 55, 'anulada' => 0],
            ['id' => 101, 'empresa_id' => 5, 'sucursal_id' => 7, 'equipo_id' => 10, 'fecha_lectura' => '2026-09-25 08:00:00', 'kilometraje' => 185000, 'horometro' => null, 'origen' => 'MANUAL', 'usuario_id' => 55, 'anulada' => 0],
        ]);
    }

    private function useCase(array $settings = [], bool $gatewayAvailable = true, ?string $failWith = null): ClaimReadingReminder
    {
        $this->gateway->available = $gatewayAvailable;
        $this->gateway->failWith = $failWith;

        $defaults = [
            'whatsapp_pilot_enabled' => false,
            'whatsapp_pilot_phone' => '',
            'whatsapp_instance_id' => 'inst-global',
        ];

        return new ClaimReadingReminder(
            $this->db,
            $this->gateway,
            $this->queue,
            new FakeSettingsStore($settings + $defaults),
            new FakeNotificationClock(new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'))),
        );
    }

    private function actor(int $companyId = 5): ActorContext
    {
        return new ActorContext(4, $companyId, false, false, ['Responsable'], ['lecturas.cargar'], []);
    }

    private function deliveries(): array
    {
        return $this->db->table('notificacion_whatsapp_entregas')->get()->getResultArray();
    }

    public function testValidClaimIsSentToTheCurrentDriver(): void
    {
        $result = $this->useCase()->execute($this->actor(), 10);

        self::assertTrue($result->sent);
        self::assertSame('5493514449999', $this->gateway->sent[0]['phone'], 'El destinatario sale del chofer vigente.');
        self::assertCount(1, $this->queue->accepted);
        self::assertSame('wamid.test.1', $this->queue->accepted[0]['messageId']);
    }

    public function testClaimIsRejectedForEquipmentFromAnotherCompany(): void
    {
        $this->expectException(DomainException::class);
        $this->useCase()->execute($this->actor(5), 90);
    }

    public function testClaimIsRejectedForUnknownEquipment(): void
    {
        $this->expectException(DomainException::class);
        $this->useCase()->execute($this->actor(), 9999);
    }

    public function testClaimIsRejectedWithoutCurrentDriver(): void
    {
        $this->expectException(DomainException::class);
        $this->useCase()->execute($this->actor(), 11);
    }

    public function testClaimIsRejectedWithoutPhone(): void
    {
        $this->expectException(DomainException::class);
        $this->useCase()->execute($this->actor(), 12);
    }

    public function testClaimIsRejectedWithInvalidPhone(): void
    {
        $this->expectException(DomainException::class);
        $this->useCase()->execute($this->actor(), 14);
    }

    public function testClaimIsRejectedWhenWhatsAppIsNotConfigured(): void
    {
        $this->expectException(DomainException::class);
        $this->useCase([], false)->execute($this->actor(), 10);
    }

    public function testClaimIsRejectedWhenCompanyHasWhatsAppDisabled(): void
    {
        $this->db->table('empresas')->where('id', 5)->update(['notificaciones_whatsapp_habilitadas' => 0]);

        $this->expectException(DomainException::class);
        $this->useCase()->execute($this->actor(), 10);
    }

    public function testPilotModeRedirectsToThePilotPhoneAndIsNotTheRealDriver(): void
    {
        $result = $this->useCase([
            'whatsapp_pilot_enabled' => true,
            'whatsapp_pilot_phone' => '5493510000000',
        ])->execute($this->actor(), 10);

        self::assertTrue($result->sent);
        self::assertSame('5493510000000', $this->gateway->sent[0]['phone']);
        self::assertNotSame('5493514449999', $this->gateway->sent[0]['phone']);
        self::assertSame('piloto', $result->pilotMode);
    }

    public function testPilotWithoutValidPilotPhoneBlocksTheClaim(): void
    {
        $this->expectException(DomainException::class);
        $this->useCase(['whatsapp_pilot_enabled' => true, 'whatsapp_pilot_phone' => ''])
            ->execute($this->actor(), 10);
    }

    public function testGatewayFailureIsRecordedAndReportedWithoutSending(): void
    {
        try {
            $this->useCase([], true, 'Vogel WhatsApp API rechazó el envío (HTTP 500).')
                ->execute($this->actor(), 10);
            self::fail('Debía lanzar DomainException.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('No se pudo enviar el reclamo', $exception->getMessage());
        }

        self::assertSame([], $this->gateway->sent);
        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries);
        self::assertSame('ERROR', $deliveries[0]['estado']);
        self::assertNotEmpty($deliveries[0]['ultimo_error']);
    }

    public function testImmediateRepetitionIsBlockedAndDoesNotSendTwice(): void
    {
        $useCase = $this->useCase();

        self::assertTrue($useCase->execute($this->actor(), 10)->sent);

        $second = $useCase->execute($this->actor(), 10);

        self::assertFalse($second->sent);
        self::assertStringContainsString('Ya se reclamó', (string) $second->error);
        self::assertCount(1, $this->gateway->sent, 'No debe enviarse un segundo WhatsApp.');
        self::assertCount(1, $this->deliveries());
    }

    public function testMessageUsesThePublicLinkOfThatEquipment(): void
    {
        $this->useCase()->execute($this->actor(), 10);
        $message = $this->gateway->sent[0]['message'];

        self::assertStringContainsString('mantenimiento/publico/equipo/', $message);
        self::assertStringContainsString('/lectura', $message);

        // El token del mensaje debe resolver exactamente a empresa 5 / equipo 10.
        preg_match('#publico/equipo/([^/]+)/lectura#', $message, $matches);
        self::assertNotEmpty($matches, 'El mensaje debe incluir el enlace público.');

        $resolved = $this->db->table('equipo_tokens_publicos')
            ->where('token_hash', hash('sha256', rawurldecode($matches[1])))
            ->get()
            ->getRowArray();

        self::assertNotNull($resolved);
        self::assertSame(5, (int) $resolved['empresa_id']);
        self::assertSame(10, (int) $resolved['equipo_id']);
    }

    public function testMessageShowsTheLastReadingWithItsOwnKilometers(): void
    {
        $this->useCase()->execute($this->actor(), 10);
        $message = $this->gateway->sent[0]['message'];

        self::assertStringContainsString('185.000', $message, 'Debe mostrar el km de la última lectura.');
        self::assertStringContainsString('25/09/2026', $message);
        self::assertStringNotContainsString('180.000', $message);
    }

    public function testMessageForEquipmentWithoutReadingDoesNotInventData(): void
    {
        $this->db->table('employee_equipment_assignments')->insert([
            'id' => 6, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 13, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null,
        ]);

        $this->useCase()->execute($this->actor(), 13);
        $message = $this->gateway->sent[0]['message'];

        self::assertStringContainsString('Todavía no tenemos una lectura registrada', $message);
    }

    public function testDeliveryIsPersistedOnlyWithColumnsThatExist(): void
    {
        $this->useCase()->execute($this->actor(), 10);
        $delivery = $this->deliveries()[0];

        self::assertSame(ClaimReadingReminder::EVENT_TYPE, $delivery['tipo_evento']);
        self::assertSame(5, (int) $delivery['empresa_id']);
        self::assertSame(10, (int) $delivery['equipo_id']);
        self::assertSame(55, (int) $delivery['empleado_id']);
        self::assertSame('inst-5', $delivery['instance_id']);
        self::assertSame('ACEPTADA', $delivery['estado']);
        self::assertNotEmpty($delivery['clave_entrega']);
        self::assertNotEmpty($delivery['gateway_message_id']);

        // Columnas que NUNCA existieron en el esquema.
        self::assertArrayNotHasKey('created_by', $delivery);
        self::assertArrayNotHasKey('deleted_at', $delivery);
    }

    public function testCompanyInstanceFallsBackToGlobalWhenEmpty(): void
    {
        $this->db->table('empresas')->where('id', 5)->update(['whatsapp_instance_id' => '']);

        $this->useCase()->execute($this->actor(), 10);

        self::assertSame('inst-global', $this->gateway->sent[0]['instanceId']);
    }
}
