<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notifications;

use App\Application\Notifications\Port\NotificationClock;
use App\Domain\Notifications\WebPushSubscription;
use App\Infrastructure\Notifications\CodeIgniterWebPushSubscriptionStore;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class WebPushSubscriptionStoreTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE webpush_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario_id INTEGER NOT NULL,
            endpoint_hash VARCHAR(64) NOT NULL,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            content_encoding VARCHAR(20) NOT NULL,
            user_agent VARCHAR(500) NULL,
            nombre_dispositivo VARCHAR(100) NULL,
            fecha_alta DATETIME NOT NULL,
            ultimo_uso DATETIME NULL,
            activo INTEGER NOT NULL DEFAULT 1,
            fecha_baja DATETIME NULL,
            ultimo_error VARCHAR(1000) NULL
        )');
    }

    protected function tearDown(): void
    {
        $this->database->close();
    }

    public function testTransientFailureKeepsDeviceActiveAndSuccessfulRecoveryClearsError(): void
    {
        $clock = new FixedNotificationClock(new DateTimeImmutable('2026-08-17 15:00:00'));
        $store = new CodeIgniterWebPushSubscriptionStore($clock, $this->database);
        $subscription = new WebPushSubscription(10, 'https://push.example/device-a', 'p256dh', 'auth', 'PC', 'UA');
        $store->upsert($subscription);

        $store->markFailed($subscription->endpoint, 'Gateway temporalmente no disponible');
        $row = $this->row($subscription);
        self::assertSame(1, (int) $row['activo']);
        self::assertNull($row['fecha_baja']);
        self::assertSame('Gateway temporalmente no disponible', $row['ultimo_error']);

        $clock->setNow(new DateTimeImmutable('2026-08-17 15:01:00'));
        $store->markDelivered($subscription->endpoint);
        $row = $this->row($subscription);
        self::assertSame(1, (int) $row['activo']);
        self::assertSame('2026-08-17 15:01:00', $row['ultimo_uso']);
        self::assertNull($row['ultimo_error']);
    }

    public function testExpiredEndpointIsDeactivatedAndNotReactivatedByDeliveryAudit(): void
    {
        $clock = new FixedNotificationClock(new DateTimeImmutable('2026-08-17 15:00:00'));
        $store = new CodeIgniterWebPushSubscriptionStore($clock, $this->database);
        $subscription = new WebPushSubscription(10, 'https://push.example/device-b', 'p256dh', 'auth', 'Teléfono', 'UA');
        $store->upsert($subscription);

        $store->markInvalid($subscription->endpoint, 'Endpoint expirado');
        $clock->setNow(new DateTimeImmutable('2026-08-17 15:01:00'));
        $store->markDelivered($subscription->endpoint);
        $row = $this->row($subscription);

        self::assertSame(0, (int) $row['activo']);
        self::assertSame('2026-08-17 15:00:00', $row['fecha_baja']);
        self::assertSame('Endpoint expirado', $row['ultimo_error']);
        self::assertSame([], $store->activeForUser(10));
    }

    /** @return array<string,mixed> */
    private function row(WebPushSubscription $subscription): array
    {
        return $this->database->table('webpush_subscriptions')
            ->where('endpoint_hash', $subscription->endpointHash())
            ->get()->getRowArray() ?: [];
    }
}

final class FixedNotificationClock implements NotificationClock
{
    public function __construct(private DateTimeImmutable $current)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function setNow(DateTimeImmutable $current): void
    {
        $this->current = $current;
    }
}
