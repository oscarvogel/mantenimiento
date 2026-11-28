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
 * Revalidación just-in-time y contadores de cancelación (#594).
 *
 * due() ya filtra, pero entre due() y sendText() hay una ventana real. El
 * despacho vuelve a evaluar el predicado inmediatamente antes de enviar.
 */
final class NotificationDispatchEligibilityTest extends TestCase
{
    public function testBlockedDeliveryIsCancelledInsteadOfSent(): void
    {
        $queue = new EligibilityFakeWhatsAppQueue();
        $queue->dueRows = [$this->deliveryRow(1)];
        $queue->blockedBeforeSend = [
            1 => DriverNotificationEligibility::blocked(
                DriverNotificationEligibility::ASIGNACION_CERRADA,
                'La asignación del chofer está cerrada.',
            ),
        ];
        $gateway = new EligibilityFakeGateway();

        $result = $this->dispatch($queue, $gateway)->execute('eligibility-blocked');

        self::assertSame([], $gateway->sent);
        self::assertSame(0, $result['whatsapp_sent']);
        self::assertSame(1, $result['whatsapp_cancelled']);
        self::assertSame(1, $result['whatsapp_cancelled_asignacion_cerrada']);
        self::assertSame(0, $result['skipped']);
        self::assertCount(1, $queue->cancelled);
        self::assertSame(1, $queue->cancelled[0]['deliveryId']);
        self::assertSame(DriverNotificationEligibility::ASIGNACION_CERRADA, $queue->cancelled[0]['reasonCode']);
    }

    public function testEligibleDeliveryIsSent(): void
    {
        $queue = new EligibilityFakeWhatsAppQueue();
        $queue->dueRows = [$this->deliveryRow(1)];
        $gateway = new EligibilityFakeGateway();

        $result = $this->dispatch($queue, $gateway)->execute('eligibility-ok');

        self::assertCount(1, $gateway->sent);
        self::assertSame(1, $result['whatsapp_sent']);
        self::assertSame(0, $result['whatsapp_cancelled']);
        self::assertSame([], $queue->cancelled);
    }

    public function testCancellationsFromDueAreAddedToTheCycleSummary(): void
    {
        $queue = new EligibilityFakeWhatsAppQueue();
        $queue->lastSummary = [
            'total' => 3,
            DriverNotificationEligibility::EMPLEADO_INACTIVO => 2,
            DriverNotificationEligibility::WHATSAPP_EMPRESA_DESHABILITADO => 1,
        ];
        $gateway = new EligibilityFakeGateway();

        $result = $this->dispatch($queue, $gateway)->execute('eligibility-summary');

        self::assertSame(3, $result['whatsapp_cancelled']);
        self::assertSame(2, $result['whatsapp_cancelled_empleado_inactivo']);
        self::assertSame(1, $result['whatsapp_cancelled_whatsapp_empresa_deshabilitado']);
        self::assertSame([], $gateway->sent);
    }

    private function dispatch(EligibilityFakeWhatsAppQueue $queue, EligibilityFakeGateway $gateway): RunNotificationDispatch
    {
        return new RunNotificationDispatch(
            new EligibilityFakeDeliveryQueue(),
            new EligibilityFakeEmailGateway(),
            new EligibilityFakePushGateway(),
            new EligibilityFakeProcess(),
            $queue,
            $gateway,
        );
    }

    /** @return array<string,mixed> */
    private function deliveryRow(int $id): array
    {
        return [
            'id' => $id,
            'empresa_id' => 5,
            'equipo_id' => 10,
            'empleado_id' => 55,
            'tipo_evento' => 'equipo.recordatorio_lectura_semanal',
            'clave_entrega' => 'prueba:' . $id,
            'telefono' => '5493514449999',
            'mensaje' => 'Recordatorio',
            'external_ref' => 'prueba:' . $id,
            'instance_id' => 'inst-5',
            'intentos' => 0,
        ];
    }
}

final class EligibilityFakeDeliveryQueue implements NotificationDeliveryQueue
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

final class EligibilityFakeEmailGateway implements EmailNotificationGateway
{
    public function sendDigest(string $recipient, array $notifications): void {}
}

final class EligibilityFakePushGateway implements WebPushGateway
{
    public function sendToUser(int $userId, string $title, string $summary, ?string $url): array
    {
        return ['sent' => 0, 'expired' => 0, 'failed' => 0];
    }
}

final class EligibilityFakeProcess implements NotificationProcessControl
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

final class EligibilityFakeWhatsAppQueue implements WhatsAppNotificationDeliveryQueue
{
    /** @var list<array<string,mixed>> */
    public array $dueRows = [];

    /** @var array<int,DriverNotificationEligibility> */
    public array $blockedBeforeSend = [];

    /** @var array<string,int> */
    public array $lastSummary = ['total' => 0];

    /** @var list<array{deliveryId:int,reasonCode:string,reasonText:string}> */
    public array $cancelled = [];

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
        return $this->dueRows;
    }

    public function lastCancellationSummary(): array
    {
        return $this->lastSummary;
    }

    public function stillPending(int $deliveryId): bool
    {
        return true;
    }

    public function revalidateBeforeSend(int $deliveryId): ?DriverNotificationEligibility
    {
        return $this->blockedBeforeSend[$deliveryId] ?? null;
    }

    public function cancelled(int $deliveryId, string $reasonCode, string $reasonText): void
    {
        $this->cancelled[] = [
            'deliveryId' => $deliveryId,
            'reasonCode' => $reasonCode,
            'reasonText' => $reasonText,
        ];
    }

    public function accepted(int $deliveryId, string $messageId, string $status): void {}

    public function awaitingConfirmation(int $limit): array
    {
        return [];
    }

    public function reconcileStatus(int $deliveryId, string $status, ?string $providerMessageId = null, ?string $error = null): void {}

    public function skipped(int $deliveryId, string $reason): void {}

    public function failed(int $deliveryId, string $error, bool $retryable): void {}
}

final class EligibilityFakeGateway implements WhatsAppNotificationGateway
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
