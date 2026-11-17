<?php

declare(strict_types=1);

use App\Application\Notifications\NotificationChannelPolicy;
use App\Application\Notifications\NotificationDeliverySchedule;
use App\Application\Notifications\Port\NotificationChannelAvailability;
use App\Application\Notifications\Port\NotificationDeliveryQueue;
use App\Domain\Notifications\DeliveryMode;
use App\Domain\Notifications\NotifiableEvent;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationChannelDisabledException;
use App\Domain\Notifications\NotificationPreference;
use App\Domain\Notifications\NotificationSeverity;
use App\Infrastructure\Notifications\CodeIgniterGlobalNotificationChannelAvailability;
use App\Infrastructure\Notifications\CodeIgniterNotificationDeliveryQueue;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\Notifications\FixedNotificationClock;

/**
 * Disponibilidad efectiva del canal de correo (#146).
 *
 * Con el canal deshabilitado por el administrador:
 * - no se encola ninguna entrega por correo (una preferencia personal no
 *   habilita un canal apagado);
 * - la entrega de empresa queda registrada como omitida, con el motivo;
 * - el despacho no intenta enviar, no cuenta fallas ni reintentos, y deja la
 *   entrega en espera con el motivo escrito;
 * - cuando el canal vuelve a estar disponible, la entrega en espera se envía
 *   una sola vez.
 */
final class NotificationChannelAvailabilityTest extends TestCase
{
    private BaseConnection $db;

    private FixedNotificationClock $clock;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión del canal requiere sqlite3.');
        }

        $this->db = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
        ], false);

        $this->clock = new FixedNotificationClock(new DateTimeImmutable('2026-11-17 07:00:00', new DateTimeZone('UTC')));

        $this->db->query('CREATE TABLE notificacion_entregas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            notificacion_id INTEGER NOT NULL,
            canal VARCHAR(16) NOT NULL,
            clave_entrega VARCHAR(220) NOT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT \'PENDIENTE\',
            intentos INTEGER NOT NULL DEFAULT 0,
            proximo_intento DATETIME NULL,
            enviada_en DATETIME NULL,
            ultimo_error VARCHAR(1000) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL
        )');
        $this->db->query('CREATE UNIQUE INDEX ux_clave_entrega ON notificacion_entregas (clave_entrega)');
        $this->db->query('CREATE TABLE notificacion_empresa_entregas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            empresa_id INTEGER NOT NULL,
            tipo_evento VARCHAR(80) NOT NULL,
            destinatario VARCHAR(255) NULL,
            clave_entrega VARCHAR(220) NOT NULL,
            titulo VARCHAR(180) NOT NULL,
            resumen VARCHAR(500) NOT NULL,
            url VARCHAR(500) NULL,
            estado VARCHAR(20) NOT NULL DEFAULT \'PENDIENTE\',
            intentos INTEGER NOT NULL DEFAULT 0,
            proximo_intento DATETIME NULL,
            enviada_en DATETIME NULL,
            ultimo_error VARCHAR(1000) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL
        )');
        $this->db->query('CREATE UNIQUE INDEX ux_clave_entrega_empresa ON notificacion_empresa_entregas (clave_entrega)');
        $this->db->query('CREATE TABLE empresas (
            id INTEGER PRIMARY KEY,
            email VARCHAR(255) NULL,
            email_notificaciones VARCHAR(255) NULL,
            notificaciones_email_habilitadas INTEGER NOT NULL DEFAULT 1,
            estado INTEGER NOT NULL DEFAULT 1,
            deleted_at DATETIME NULL
        )');
        $this->db->query('CREATE TABLE usuarios (
            id INTEGER PRIMARY KEY,
            empresa_id INTEGER NOT NULL,
            nombre VARCHAR(255) NOT NULL,
            email VARCHAR(255) NULL,
            activo INTEGER NOT NULL DEFAULT 1,
            deleted_at DATETIME NULL
        )');
        $this->db->query('CREATE TABLE notificaciones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            empresa_id INTEGER NOT NULL,
            sucursal_id INTEGER NULL,
            usuario_id INTEGER NOT NULL,
            tipo_evento VARCHAR(80) NOT NULL,
            severidad VARCHAR(16) NOT NULL,
            titulo VARCHAR(180) NOT NULL,
            resumen VARCHAR(500) NOT NULL,
            entidad_tipo VARCHAR(80) NOT NULL,
            entidad_id VARCHAR(80) NOT NULL,
            url VARCHAR(500) NULL,
            clave_evento VARCHAR(190) NOT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT \'PENDIENTE\',
            leida_en DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL
        )');
        $this->db->query('CREATE UNIQUE INDEX ux_clave_evento ON notificaciones (usuario_id, clave_evento)');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testEmailDeliveryIsNotQueuedWhenTheChannelIsDisabled(): void
    {
        $queue = $this->queue(false, true);

        $queue->schedule(10, 7, 'evento:1', NotificationSeverity::WARNING, $this->preference());

        self::assertSame(0, (int) $this->db->table('notificacion_entregas')->where('canal', 'EMAIL')->countAllResults());
        self::assertSame(1, (int) $this->db->table('notificacion_entregas')->where('canal', 'PUSH')->countAllResults());
    }

    public function testEmailDeliveryIsQueuedWhenTheChannelIsAvailable(): void
    {
        $queue = $this->queue(true, true);

        $queue->schedule(10, 7, 'evento:1', NotificationSeverity::WARNING, $this->preference());

        self::assertSame(1, (int) $this->db->table('notificacion_entregas')->where('canal', 'EMAIL')->countAllResults());
    }

    public function testCompanyEmailIsRecordedAsOmittedWithTheExplicitReason(): void
    {
        $this->db->table('empresas')->insert([
            'id' => 5, 'email' => 'contacto@example.test', 'email_notificaciones' => null,
            'notificaciones_email_habilitadas' => 1, 'estado' => 1, 'deleted_at' => null,
        ]);
        $queue = $this->queue(false, true);

        $queue->scheduleCompany($this->companyEvent());

        $row = $this->db->table('notificacion_empresa_entregas')->get()->getRowArray();
        self::assertNotNull($row);
        self::assertSame('OMITIDA', (string) $row['estado']);
        self::assertStringContainsString('deshabilitado', (string) $row['ultimo_error']);
    }

    public function testCompanyEmailIsQueuedWhenTheChannelIsAvailable(): void
    {
        $this->db->table('empresas')->insert([
            'id' => 5, 'email' => 'contacto@example.test', 'email_notificaciones' => null,
            'notificaciones_email_habilitadas' => 1, 'estado' => 1, 'deleted_at' => null,
        ]);
        $queue = $this->queue(true, true);

        $queue->scheduleCompany($this->companyEvent());

        $row = $this->db->table('notificacion_empresa_entregas')->get()->getRowArray();
        self::assertNotNull($row);
        self::assertSame('PENDIENTE', (string) $row['estado']);
        self::assertNull($row['ultimo_error']);
    }

    public function testDeferredDeliveryKeepsItsStateAndTheReason(): void
    {
        $this->db->table('notificacion_entregas')->insert([
            'notificacion_id' => 10, 'canal' => 'EMAIL', 'clave_entrega' => 'evento:1:usuario:7:email',
            'estado' => 'PENDIENTE', 'intentos' => 0, 'created_at' => '2026-11-17 07:00:00',
        ]);
        $queue = $this->queue(false, true);
        $deliveryId = (int) $this->db->table('notificacion_entregas')->select('id')->get()->getRowArray()['id'];

        $queue->deferred($deliveryId, 'El canal EMAIL está deshabilitado: el administrador lo apagó.');

        $row = $this->db->table('notificacion_entregas')->where('id', $deliveryId)->get()->getRowArray();
        self::assertSame('PENDIENTE', (string) $row['estado'], 'La entrega queda en espera: no se pierde.');
        self::assertSame(0, (int) $row['intentos'], 'No se cuenta como intento.');
        self::assertStringContainsString('deshabilitado', (string) $row['ultimo_error']);
    }

    public function testGatewayRefusalIsNotAFailureAndKeepsTheDeliveryPending(): void
    {
        $deliveryId = $this->seedPendingEmailDelivery();
        $queue = $this->queue(false, true);

        $summary = (new \App\Application\Notifications\RunNotificationDispatch(
            $queue,
            new ChannelAvailabilityDisabledEmailGateway(),
            new ChannelAvailabilityEmptyPushGateway(),
            new ChannelAvailabilityProcessControl(),
        ))->execute('2026-11-17-07-00');

        self::assertSame(0, $summary['failed'], 'Un canal deshabilitado no es una falla.');
        self::assertSame(0, $summary['retry'], 'No genera reintentos.');
        self::assertSame(1, $summary['email_channel_disabled'], 'Queda registrado de forma explícita.');
        self::assertSame(0, $summary['email_sent']);

        $row = $this->db->table('notificacion_entregas')->where('id', $deliveryId)->get()->getRowArray();
        self::assertSame('PENDIENTE', (string) $row['estado'], 'La entrega sigue pendiente para cuando vuelva el canal.');
        self::assertSame(0, (int) $row['intentos']);
        self::assertStringContainsString('deshabilitado', (string) $row['ultimo_error']);
    }

    public function testDeliveryIsSentOnceWhenTheChannelReturns(): void
    {
        $deliveryId = $this->seedPendingEmailDelivery();
        $queue = $this->queue(true, true);
        $gateway = new ChannelAvailabilityRecordingEmailGateway();

        $summary = (new \App\Application\Notifications\RunNotificationDispatch(
            $queue,
            $gateway,
            new ChannelAvailabilityEmptyPushGateway(),
            new ChannelAvailabilityProcessControl(),
        ))->execute('2026-11-17-07-00');

        self::assertSame(1, $summary['email_sent']);
        self::assertSame(0, $summary['failed']);
        self::assertCount(1, $gateway->batches);
        self::assertSame('ENVIADA', (string) $this->db->table('notificacion_entregas')->where('id', $deliveryId)->get()->getRowArray()['estado']);
        self::assertNull($this->db->table('notificacion_entregas')->where('id', $deliveryId)->get()->getRowArray()['ultimo_error']);
    }

    public function testTemporarySmtpFailureStillCountsAsAFailureWithRetry(): void
    {
        $deliveryId = $this->seedPendingEmailDelivery();
        $queue = $this->queue(true, true);

        $summary = (new \App\Application\Notifications\RunNotificationDispatch(
            $queue,
            new ChannelAvailabilityFailingEmailGateway(),
            new ChannelAvailabilityEmptyPushGateway(),
            new ChannelAvailabilityProcessControl(),
        ))->execute('2026-11-17-07-00');

        self::assertSame(1, $summary['failed'], 'Un error de SMTP sigue siendo una falla.');
        self::assertSame(1, $summary['retry']);
        self::assertSame(0, $summary['email_channel_disabled']);

        $row = $this->db->table('notificacion_entregas')->where('id', $deliveryId)->get()->getRowArray();
        self::assertSame('REINTENTO', (string) $row['estado']);
        self::assertSame(1, (int) $row['intentos']);
    }

    /**
     * El remitente inválido no es un canal apagado: es una configuración
     * equivocada, y tiene que verse como una falla en el despacho.
     *
     * Si la disponibilidad exigiera un remitente válido, un correo mal
     * configurado dejaría de encolarse sin dejar rastro y el operador no
     * vería ningún error.
     */
    public function testEnabledChannelWithAnInvalidSenderIsStillQueued(): void
    {
        $availability = new CodeIgniterGlobalNotificationChannelAvailability(
            new ChannelAvailabilitySettingsStore(['smtp_enabled' => true, 'smtp_from_email' => 'no-es-un-correo']),
        );

        self::assertTrue($availability->isAvailable(NotificationChannel::EMAIL));

        $queue = new CodeIgniterNotificationDeliveryQueue(
            $this->clock,
            new NotificationDeliverySchedule(),
            new NotificationChannelPolicy(),
            $availability,
            '07:00',
            $this->db,
        );

        $queue->schedule(10, 7, 'evento:1', NotificationSeverity::WARNING, $this->preference());

        self::assertSame(
            1,
            (int) $this->db->table('notificacion_entregas')->where('canal', 'EMAIL')->countAllResults(),
            'Con el canal habilitado la entrega se encola y el error de remitente se ve en el despacho.',
        );
    }

    /**
     * Deja lista una entrega de correo pendiente de un usuario activo.
     *
     * El despacho resuelve las entregas con un join contra `notificaciones` y
     * `usuarios`, así que la entrega sólo existe para el despachador cuando la
     * notificación y su usuario están presentes.
     */
    private function seedPendingEmailDelivery(): int
    {
        $this->db->table('usuarios')->insert([
            'id' => 7, 'empresa_id' => 5, 'nombre' => 'Operador de prueba',
            'email' => 'operador@example.test', 'activo' => 1, 'deleted_at' => null,
        ]);
        $this->db->table('notificaciones')->insert([
            'id' => 10, 'empresa_id' => 5, 'sucursal_id' => null, 'usuario_id' => 7,
            'tipo_evento' => 'evento', 'severidad' => 'WARNING', 'titulo' => 'Titulo',
            'resumen' => 'Detalle', 'entidad_tipo' => 'equipo', 'entidad_id' => '1',
            'url' => null, 'clave_evento' => 'evento:1', 'estado' => 'PENDIENTE',
            'leida_en' => null, 'created_at' => '2026-11-17 07:00:00', 'updated_at' => null,
        ]);
        $this->db->table('notificacion_entregas')->insert([
            'notificacion_id' => 10, 'canal' => 'EMAIL', 'clave_entrega' => 'evento:1:usuario:7:email',
            'estado' => 'PENDIENTE', 'intentos' => 0, 'created_at' => '2026-11-17 07:00:00',
        ]);

        return (int) $this->db->table('notificacion_entregas')->select('id')->get()->getRowArray()['id'];
    }

    private function queue(bool $emailAvailable, bool $pushAvailable): CodeIgniterNotificationDeliveryQueue
    {
        return new CodeIgniterNotificationDeliveryQueue(
            $this->clock,
            new NotificationDeliverySchedule(),
            new NotificationChannelPolicy(),
            new ChannelAvailabilityStub($emailAvailable, $pushAvailable),
            '07:00',
            $this->db,
        );
    }

    private function preference(): NotificationPreference
    {
        return new NotificationPreference(DeliveryMode::IMMEDIATE, DeliveryMode::IMMEDIATE, DeliveryMode::IMMEDIATE);
    }

    private function companyEvent(): NotifiableEvent
    {
        return new NotifiableEvent(
            5,
            null,
            'preventivo.vencido',
            NotificationSeverity::WARNING,
            'Plan vencido',
            'Detalle del plan vencido',
            'plan',
            '1',
            'preventivo.vencido:1',
            '/mantenimiento/planes/1',
            new DateTimeImmutable('2026-11-17 07:00:00', new DateTimeZone('UTC')),
        );
    }
}

final class ChannelAvailabilityStub implements NotificationChannelAvailability
{
    public function __construct(private bool $email, private bool $push)
    {
    }

    public function isAvailable(NotificationChannel $channel): bool
    {
        return match ($channel) {
            NotificationChannel::EMAIL => $this->email,
            NotificationChannel::PUSH => $this->push,
            NotificationChannel::WHATSAPP => false,
        };
    }

    public function unavailableReason(NotificationChannel $channel): string
    {
        return 'El canal ' . $channel->value . ' está deshabilitado por el administrador del sistema.';
    }
}

final class ChannelAvailabilitySettingsStore implements \App\Application\Notifications\Port\GlobalNotificationSettingsStore
{
    /** @param array<string,mixed> $settings */
    public function __construct(private array $settings)
    {
    }

    public function get(): array
    {
        return $this->settings;
    }

    public function save(array $settings, int $actorId): void
    {
        $this->settings = $settings;
    }
}

final class ChannelAvailabilityDisabledEmailGateway implements \App\Application\Notifications\Port\EmailNotificationGateway
{
    public function sendDigest(string $recipient, array $notifications): void
    {
        throw NotificationChannelDisabledException::forChannel(
            NotificationChannel::EMAIL,
            'El canal de correo está deshabilitado por el administrador del sistema.',
        );
    }
}

final class ChannelAvailabilityRecordingEmailGateway implements \App\Application\Notifications\Port\EmailNotificationGateway
{
    /** @var list<array<int,array<string,mixed>>> */
    public array $batches = [];

    public function sendDigest(string $recipient, array $notifications): void
    {
        $this->batches[] = $notifications;
    }
}

final class ChannelAvailabilityFailingEmailGateway implements \App\Application\Notifications\Port\EmailNotificationGateway
{
    public function sendDigest(string $recipient, array $notifications): void
    {
        throw new RuntimeException('SMTP temporal');
    }
}

final class ChannelAvailabilityEmptyPushGateway implements \App\Application\Notifications\Port\WebPushGateway
{
    public function sendToUser(int $userId, string $title, string $body, ?string $url = null): array
    {
        return ['sent' => 0, 'expired' => 0, 'failed' => 0];
    }
}

final class ChannelAvailabilityProcessControl implements \App\Application\Notifications\Port\NotificationProcessControl
{
    public function acquire(string $process, int $ttlSeconds): ?string
    {
        return 'token';
    }

    public function start(string $process, string $executionKey): ?int
    {
        return 1;
    }

    public function finish(int $executionId, array $summary): void
    {
    }

    public function fail(int $executionId, string $error): void
    {
    }

    public function release(string $process, string $token): void
    {
    }
}
