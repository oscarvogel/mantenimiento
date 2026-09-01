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

final class NotificationEmailDispatchTest extends TestCase
{
    public function testDigestIsSeparatedByRecipientAndBranch(): void
    {
        $queue = new Issue144Queue();
        $queue->dueByChannel['EMAIL'] = [
            $this->delivery(1, 10, 1, 'ops@example.com'),
            $this->delivery(2, 10, 1, 'ops@example.com'),
            $this->delivery(3, 10, 2, 'ops@example.com'),
        ];
        $email = new Issue144RecordingEmail();

        $result = (new RunNotificationDispatch($queue, $email, new Issue144EmptyPush(), new Issue144Process()))->execute('issue-144-branches');

        self::assertCount(2, $email->batches);
        self::assertSame([1, 2], array_column($email->batches[0], 'id'));
        self::assertSame([3], array_column($email->batches[1], 'id'));
        self::assertSame([1, 2, 3], $queue->deliveredIds);
        self::assertSame(3, $result['email_sent']);
    }

    public function testSmtpFailureRemainsRetryable(): void
    {
        $queue = new Issue144Queue();
        $queue->dueByChannel['EMAIL'] = [$this->delivery(7, 10, 1, 'ops@example.com', 0)];

        $result = (new RunNotificationDispatch($queue, new Issue144FailingEmail(), new Issue144EmptyPush(), new Issue144Process()))->execute('issue-144-retry');

        self::assertSame([[7, true]], $queue->failedIds);
        self::assertSame(1, $result['failed']);
        self::assertSame(1, $result['retry']);
    }

    public function testRepeatedExecutionKeyIsNoOp(): void
    {
        $process = new Issue144Process();
        $process->startId = null;
        $queue = new Issue144Queue();

        $result = (new RunNotificationDispatch($queue, new Issue144RecordingEmail(), new Issue144EmptyPush(), $process))->execute('issue-144-same-key');

        self::assertSame(1, $result['already_completed']);
        self::assertSame([], $queue->deliveredIds);
        self::assertTrue($process->released);
    }

    public function testCriticalOnlyPolicyIsCoveredByExistingChannelContract(): void
    {
        $policy = new App\Application\Notifications\NotificationChannelPolicy();
        self::assertTrue($policy->shouldSchedule(App\Domain\Notifications\DeliveryMode::CRITICAL_ONLY, NotificationSeverity::CRITICAL, true));
        self::assertFalse($policy->shouldSchedule(App\Domain\Notifications\DeliveryMode::CRITICAL_ONLY, NotificationSeverity::WARNING, true));
    }

    /** @return array<string,mixed> */
    private function delivery(int $id, int $userId, int $branchId, string $email, int $attempts = 0): array
    {
        return [
            'id' => $id,
            'notification_id' => $id,
            'usuario_id' => $userId,
            'sucursal_id' => $branchId,
            'email' => $email,
            'canal' => 'EMAIL',
            'titulo' => 'Aviso ' . $id,
            'resumen' => 'Detalle ' . $id,
            'url' => '/mantenimiento',
            'severidad' => 'ADVERTENCIA',
            'intentos' => $attempts,
        ];
    }
}

final class Issue144Queue implements NotificationDeliveryQueue
{
    public array $dueByChannel = [];
    public array $deliveredIds = [];
    public array $failedIds = [];

    public function schedule(int $notificationId, int $userId, string $eventKey, NotificationSeverity $severity, NotificationPreference $preference): void {}
    public function due(string $channel, int $limit): array { return $this->dueByChannel[$channel] ?? []; }
    public function delivered(int $deliveryId): void { $this->deliveredIds[] = $deliveryId; }
    public function skipped(int $deliveryId, string $reason): void {}
    public function failed(int $deliveryId, string $error, bool $retryable): void { $this->failedIds[] = [$deliveryId, $retryable]; }
}

final class Issue144RecordingEmail implements EmailNotificationGateway
{
    public array $batches = [];
    public function sendDigest(string $recipient, array $notifications): void { $this->batches[] = $notifications; }
}

final class Issue144FailingEmail implements EmailNotificationGateway
{
    public function sendDigest(string $recipient, array $notifications): void { throw new RuntimeException('SMTP temporal'); }
}

final class Issue144EmptyPush implements WebPushGateway
{
    public function sendToUser(int $userId, string $title, string $summary, ?string $url): array { return ['sent' => 0, 'expired' => 0, 'failed' => 0]; }
}

final class Issue144Process implements NotificationProcessControl
{
    public bool $released = false;
    public ?int $startId = 1;
    public function acquire(string $process, int $ttlSeconds): ?string { return 'token'; }
    public function start(string $process, string $executionKey): ?int { return $this->startId; }
    public function finish(int $executionId, array $summary): void {}
    public function fail(int $executionId, string $error): void {}
    public function release(string $process, string $token): void { $this->released = true; }
}
