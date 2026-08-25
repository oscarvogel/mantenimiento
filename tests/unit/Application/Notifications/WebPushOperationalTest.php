<?php

declare(strict_types=1);

use App\Application\Notifications\Port\EmailNotificationGateway;
use App\Application\Notifications\Port\NotificationDeliveryQueue;
use App\Application\Notifications\Port\NotificationProcessControl;
use App\Application\Notifications\Port\WebPushGateway;
use App\Application\Notifications\RunNotificationDispatch;
use App\Domain\Notifications\NotificationPreference;
use App\Domain\Notifications\NotificationSeverity;
use PHPUnit\Framework\TestCase;

final class WebPushOperationalTest extends TestCase
{
    public function testTemporaryPushFailureIsRetriedInsteadOfSkipped(): void
    {
        $queue = new WebPushQueueFake();
        $queue->duePush = [[
            'id' => 9,
            'notification_id' => 20,
            'usuario_id' => 77,
            'email' => 'tecnico@example.com',
            'canal' => 'PUSH',
            'titulo' => 'OT demorada',
            'resumen' => 'Requiere atención',
            'url' => '/mantenimiento/ordenes?orden_id=44',
            'severidad' => 'CRITICA',
            'intentos' => 0,
        ]];

        $result = (new RunNotificationDispatch(
            $queue,
            new WebPushNoopEmailGateway(),
            new WebPushFailingGateway(),
            new WebPushProcessFake(),
        ))->execute('push-failure');

        self::assertSame([[9, true]], $queue->failed);
        self::assertSame([], $queue->skipped);
        self::assertSame(1, $result['failed']);
    }

    public function testNoActiveDevicesIsSkippedWithoutBreakingDispatch(): void
    {
        $queue = new WebPushQueueFake();
        $queue->duePush = [[
            'id' => 10,
            'notification_id' => 21,
            'usuario_id' => 78,
            'email' => 'operador@example.com',
            'canal' => 'PUSH',
            'titulo' => 'Aviso',
            'resumen' => 'Detalle',
            'url' => '/mantenimiento/equipos/5',
            'severidad' => 'AVISO',
            'intentos' => 0,
        ]];

        $result = (new RunNotificationDispatch(
            $queue,
            new WebPushNoopEmailGateway(),
            new WebPushEmptyGateway(),
            new WebPushProcessFake(),
        ))->execute('push-empty');

        self::assertSame([10], $queue->skipped);
        self::assertSame([], $queue->failed);
        self::assertSame(1, $result['skipped']);
    }

    public function testServiceWorkerKeepsNotificationClicksInsideAppScopeAndNavigatesExistingClient(): void
    {
        $source = file_get_contents(ROOTPATH . 'service-worker.js');
        self::assertIsString($source);
        self::assertStringContainsString('candidate.origin === scope.origin', $source);
        self::assertStringContainsString('candidate.pathname.startsWith(scope.pathname)', $source);
        self::assertStringContainsString('await inApp.navigate(target)', $source);
        self::assertStringContainsString('self.clients.openWindow(target)', $source);
    }
}

final class WebPushQueueFake implements NotificationDeliveryQueue
{
    public array $duePush = [];
    public array $failed = [];
    public array $skipped = [];
    public array $delivered = [];

    public function schedule(int $notificationId, int $userId, string $eventKey, NotificationSeverity $severity, NotificationPreference $preference): void {}
    public function due(string $channel, int $limit): array { return strtoupper($channel) === 'PUSH' ? $this->duePush : []; }
    public function delivered(int $deliveryId): void { $this->delivered[] = $deliveryId; }
    public function skipped(int $deliveryId, string $reason): void { $this->skipped[] = $deliveryId; }
    public function failed(int $deliveryId, string $error, bool $retryable): void { $this->failed[] = [$deliveryId, $retryable]; }
}

final class WebPushNoopEmailGateway implements EmailNotificationGateway
{
    public function sendDigest(string $recipient, array $notifications): void {}
}

final class WebPushFailingGateway implements WebPushGateway
{
    public function sendToUser(int $userId, string $title, string $summary, ?string $url): array
    {
        return ['sent' => 0, 'expired' => 0, 'failed' => 1];
    }
}

final class WebPushEmptyGateway implements WebPushGateway
{
    public function sendToUser(int $userId, string $title, string $summary, ?string $url): array
    {
        return ['sent' => 0, 'expired' => 0, 'failed' => 0];
    }
}

final class WebPushProcessFake implements NotificationProcessControl
{
    public function acquire(string $process, int $ttlSeconds): ?string { return 'token'; }
    public function start(string $process, string $executionKey): ?int { return 1; }
    public function finish(int $executionId, array $summary): void {}
    public function fail(int $executionId, string $error): void {}
    public function release(string $process, string $token): void {}
}
