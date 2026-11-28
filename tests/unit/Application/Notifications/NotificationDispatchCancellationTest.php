<?php

declare(strict_types=1);

use App\Application\Notifications\Port\EmailNotificationGateway;
use App\Application\Notifications\Port\NotificationDeliveryQueue;
use App\Application\Notifications\Port\NotificationProcessControl;
use App\Application\Notifications\Port\WebPushGateway;
use App\Application\Notifications\Port\WhatsAppNotificationDeliveryQueue;
use App\Application\Notifications\Port\WhatsAppNotificationGateway;
use App\Application\Notifications\RunNotificationDispatch;
use App\Domain\Notifications\DriverNotificationEligibility;
use App\Domain\Notifications\NotifiableEvent;
use App\Domain\Notifications\NotificationPreference;
use App\Domain\Notifications\NotificationSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Regresión de la cancelación concurrente: una entrega que ya estaba en el lote
 * de `due()` pero que fue cancelada antes de `sendText()` no debe salir.
 */
final class NotificationDispatchCancellationTest extends TestCase
{
    public function testCancelledDeliverySelectedByDueIsNotSent(): void
    {
        $queue = new CancellationRaceWhatsAppQueue();
        $gateway = new CancellationRaceWhatsAppGateway();

        $dispatch = new RunNotificationDispatch(
            new CancellationRaceDeliveryQueue(),
            new CancellationRaceEmailGateway(),
            new CancellationRacePushGateway(),
            new CancellationRaceProcess(),
            $queue,
            $gateway,
        );

        $result = $dispatch->execute('cancel-race');

        self::assertSame([], $gateway->sent);
        self::assertSame(0, $result['whatsapp_sent']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $result['failed']);
    }
}

final class CancellationRaceDeliveryQueue implements NotificationDeliveryQueue
{
    public function schedule(int $notificationId, int $userId, string $eventKey, NotificationSeverity $severity, NotificationPreference $preference, ?string $deliveryEmail = null): void {}

    public function due(string $channel, int $limit): array
    {
        return [];
    }

    public function delivered(int $deliveryId): void {}

    public function skipped(int $deliveryId, string $reason): void {}

    public function deferred(int $deliveryId, string $reason): void {}

    public function failed(int $deliveryId, string $error, bool $retryable): void {}
}

final class CancellationRaceEmailGateway implements EmailNotificationGateway
{
    public function sendDigest(string $recipient, array $notifications): void {}
}

final class CancellationRacePushGateway implements WebPushGateway
{
    public function sendToUser(int $userId, string $title, string $summary, ?string $url): array
    {
        return ['sent' => 0, 'expired' => 0, 'failed' => 0];
    }
}

final class CancellationRaceProcess implements NotificationProcessControl
{
    public function acquire(string $process, int $ttlSeconds): ?string
    {
        return 'token';
    }

    public function start(string $process, string $executionKey): ?int
    {
        return 1;
    }

    public function finish(int $executionId, array $summary): void {}

    public function fail(int $executionId, string $error): void {}

    public function release(string $process, string $token): void {}
}

final class CancellationRaceWhatsAppQueue implements WhatsAppNotificationDeliveryQueue
{
    public function scheduleDriverForEvent(NotifiableEvent $event): void {}

    public function scheduleUserDailyDigests(): int
    {
        return 0;
    }

    public function previewUserDailyDigests(int $companyId): array
    {
        return [];
    }

    public function scheduleUserDailyDigestTest(int $userId, int $equipmentId, string $testKey): int
    {
        return 0;
    }

    public function schedulePreventivePilotTest(int $planId, string $testKey): int
    {
        return 0;
    }

    public function scheduleWeeklyReadingReminders(bool $force = false, ?string $testKey = null): int
    {
        return 0;
    }

    public function enqueueManagementReadingReport(int $companyId, string $deliveryKey, string $phone, string $instanceId, string $message, string $scheduledAt): int
    {
        return 0;
    }

    public function due(int $limit): array
    {
        return [[
            'id' => 7373,
            'empresa_id' => 4,
            'equipo_id' => 43,
            'empleado_id' => 4,
            'tipo_evento' => 'equipo.recordatorio_lectura_semanal',
            'telefono' => '5493755545939',
            'mensaje' => 'Recordatorio',
            'external_ref' => 'test',
            'instance_id' => 'test',
            'intentos' => 0,
        ]];
    }

    public function stillPending(int $deliveryId): bool
    {
        return false;
    }

    public function lastCancellationSummary(): array
    {
        return [];
    }

    public function revalidateBeforeSend(int $deliveryId): ?DriverNotificationEligibility
    {
        return null;
    }

    public function cancelled(int $deliveryId, string $reasonCode, string $reasonText): void {}

    public function accepted(int $deliveryId, string $messageId, string $status): void {}

    public function awaitingConfirmation(int $limit): array
    {
        return [];
    }

    public function reconcileStatus(int $deliveryId, string $status, ?string $providerMessageId = null, ?string $error = null): void {}

    public function skipped(int $deliveryId, string $reason): void {}

    public function failed(int $deliveryId, string $error, bool $retryable): void {}
}

final class CancellationRaceWhatsAppGateway implements WhatsAppNotificationGateway
{
    /** @var list<array{phone:string,message:string,externalRef:string}> */
    public array $sent = [];

    public function sendText(string $phone, string $message, string $externalRef, ?string $actorId = null, ?string $actorName = null, ?string $instanceId = null): array
    {
        $this->sent[] = compact('phone', 'message', 'externalRef');

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
        return $phone;
    }
}
