<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Notifications\GetNotificationCenter;
use App\Application\Notifications\ManageNotificationPreferences;
use App\Application\Notifications\ManageWebPushSubscriptions;
use App\Application\Notifications\NotificationCenterPage;
use App\Application\Notifications\NotificationRecipient;
use App\Application\Notifications\Port\EmailNotificationGateway;
use App\Application\Notifications\Port\NotificationDeliveryQueue;
use App\Application\Notifications\Port\NotificationPreferenceStore;
use App\Application\Notifications\Port\NotificationProcessControl;
use App\Application\Notifications\Port\NotificationRecipientResolver;
use App\Application\Notifications\Port\NotificationRepository;
use App\Application\Notifications\Port\NotificationUnitOfWork;
use App\Application\Notifications\Port\WebPushGateway;
use App\Application\Notifications\Port\WebPushSubscriptionStore;
use App\Application\Notifications\PublishNotifiableEvent;
use App\Application\Notifications\RunNotificationDispatch;
use App\Application\Notifications\SendWebPushTest;
use App\Application\Notifications\NotificationDeliverySchedule;
use App\Application\Notifications\NotificationChannelPolicy;
use App\Application\Notifications\NotificationPreferenceResolution;
use App\Application\Notifications\NotificationRecipientScopePolicy;
use App\Domain\Notifications\DeliveryMode;
use App\Domain\Notifications\NotifiableEvent;
use App\Domain\Notifications\Notification;
use App\Domain\Notifications\NotificationPreference;
use App\Domain\Notifications\NotificationSeverity;
use App\Domain\Notifications\WebPushSubscription;
use PHPUnit\Framework\TestCase;

final class NotificationUseCasesTest extends TestCase
{
    public function testPublishesOncePerLogicalKeyAndRejectsCrossTenantRecipient(): void
    {
        $repository = new FakeNotificationRepository();
        $queue = new FakeNotificationDeliveryQueue();
        $resolver = new FakeNotificationRecipientResolver([
            new NotificationRecipient(10, 5, 'one@example.com'),
            new NotificationRecipient(20, 99, 'other@example.com'),
        ]);
        $useCase = new PublishNotifiableEvent($resolver, $repository, new FakeNotificationPreferenceStore(), $queue, new FakeNotificationUnitOfWork());
        $event = $this->event();

        $first = $useCase->execute($event);
        $second = $useCase->execute($event);

        self::assertSame(['created' => 1, 'duplicates' => 0, 'recipients' => 2], $first);
        self::assertSame(['created' => 0, 'duplicates' => 1, 'recipients' => 2], $second);
        self::assertCount(1, $queue->scheduled);
        self::assertSame(10, $queue->scheduled[0]['user']);
    }

    public function testNotificationCenterPassesCompanyUserAndBranchScope(): void
    {
        $repository = new FakeNotificationRepository();
        $actor = new ActorContext(10, 5, false, false, ['Técnico'], ['notificaciones.ver'], [7]);
        (new GetNotificationCenter($repository))->execute($actor, 2, 25);

        self::assertSame([5, 10, [7], 2, 25], $repository->lastScope);
    }

    public function testSuperadminCannotManageTenantPreferencesOrSubscriptions(): void
    {
        $actor = new ActorContext(1, null, true, true, ['Superadministrador'], [], []);
        $this->expectException(DomainException::class);
        (new ManageNotificationPreferences(new FakeNotificationPreferenceStore()))->list($actor);
    }

    public function testMultipleWebPushDevicesAreDelegatedToStoreAndOwnershipIsEnforced(): void
    {
        $store = new FakeWebPushSubscriptionStore();
        $handler = new ManageWebPushSubscriptions($store);
        $actor = new ActorContext(10, 5, false, true, ['Técnico'], ['notificaciones.ver'], []);

        $handler->subscribe($actor, 'https://push.example/device-a', 'key-a', 'auth-a', 'Teléfono', 'UA');
        $handler->subscribe($actor, 'https://push.example/device-b', 'key-b', 'auth-b', 'PC', 'UA');

        self::assertCount(2, $store->subscriptions);
        self::assertNotSame($store->subscriptions[0]->endpointHash(), $store->subscriptions[1]->endpointHash());

        $this->expectException(DomainException::class);
        $handler->unsubscribe($actor, 'https://push.example/not-owned');
    }

    public function testDispatchKeepsChannelFailuresRetryableAndReleasesLock(): void
    {
        $queue = new FakeNotificationDeliveryQueue();
        $queue->dueByChannel['EMAIL'] = [[
            'id' => 1, 'notification_id' => 1, 'usuario_id' => 10, 'email' => 'one@example.com', 'canal' => 'EMAIL',
            'titulo' => 'Vencido', 'resumen' => 'Plan vencido', 'url' => '/planes/1', 'severidad' => 'CRITICA', 'intentos' => 0,
        ]];
        $queue->dueByChannel['PUSH'] = [[
            'id' => 2, 'notification_id' => 1, 'usuario_id' => 10, 'email' => 'one@example.com', 'canal' => 'PUSH',
            'titulo' => 'Vencido', 'resumen' => 'Plan vencido', 'url' => '/planes/1', 'severidad' => 'CRITICA', 'intentos' => 0,
        ]];
        $process = new FakeNotificationProcessControl();
        $result = (new RunNotificationDispatch($queue, new FailingEmailGateway(), new SuccessfulPushGateway(), $process))->execute('2026-08-12');

        self::assertSame(1, $result['failed']);
        self::assertSame(1, $result['push_sent']);
        self::assertSame([[1, true]], $queue->failed);
        self::assertSame([2], $queue->delivered);
        self::assertTrue($process->released);
    }

    public function testPushWithoutDevicesIsTerminallySkipped(): void
    {
        $queue = new FakeNotificationDeliveryQueue();
        $queue->dueByChannel['PUSH'] = [[
            'id' => 2, 'notification_id' => 1, 'usuario_id' => 10, 'email' => 'one@example.com', 'canal' => 'PUSH',
            'titulo' => 'Aviso', 'resumen' => 'Detalle', 'url' => '/notificaciones', 'severidad' => 'INFO', 'intentos' => 0,
        ]];
        $result = (new RunNotificationDispatch($queue, new NoopEmailGateway(), new EmptyPushGateway(), new FakeNotificationProcessControl()))->execute('2026-08-12-empty');
        self::assertSame([2], $queue->skipped);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $queue->failed);
    }

    public function testCompletedLogicalDispatchIsSuccessfulNoOp(): void
    {
        $process = new FakeNotificationProcessControl();
        $process->startId = null;
        $queue = new FakeNotificationDeliveryQueue();
        $result = (new RunNotificationDispatch($queue, new NoopEmailGateway(), new EmptyPushGateway(), $process))->execute('already-done');
        self::assertSame(1, $result['already_completed']);
        self::assertTrue($process->released);
        self::assertSame([], $queue->delivered);
    }

    public function testDailyDigestSchedulesAtNextConfiguredRun(): void
    {
        $schedule = new NotificationDeliverySchedule();
        self::assertSame('2026-08-12 07:00:00', $schedule->nextAttempt(DeliveryMode::DAILY_DIGEST, new DateTimeImmutable('2026-08-12 06:30:00'), '07:00')->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-13 07:00:00', $schedule->nextAttempt(DeliveryMode::DAILY_DIGEST, new DateTimeImmutable('2026-08-12 08:00:00'), '07:00')->format('Y-m-d H:i:s'));
        self::assertNull($schedule->nextAttempt(DeliveryMode::IMMEDIATE, new DateTimeImmutable('2026-08-12'), '07:00'));
    }

    public function testDisabledPushChannelIsNotScheduledByPolicy(): void
    {
        $policy = new NotificationChannelPolicy();
        self::assertFalse($policy->shouldSchedule(DeliveryMode::IMMEDIATE, NotificationSeverity::CRITICAL, false));
        self::assertTrue($policy->shouldSchedule(DeliveryMode::CRITICAL_ONLY, NotificationSeverity::CRITICAL, true));
        self::assertFalse($policy->shouldSchedule(DeliveryMode::CRITICAL_ONLY, NotificationSeverity::WARNING, true));
    }

    public function testUserPreferenceOverridesRoleAndRoleOverridesSystemDefault(): void
    {
        $resolution = new NotificationPreferenceResolution();
        $role = new NotificationPreference(DeliveryMode::IMMEDIATE, DeliveryMode::DISABLED, DeliveryMode::CRITICAL_ONLY);
        $user = new NotificationPreference(DeliveryMode::IMMEDIATE, DeliveryMode::IMMEDIATE, DeliveryMode::DISABLED);
        self::assertSame($user, $resolution->resolve($user, $role));
        self::assertSame($role, $resolution->resolve(null, $role));
        self::assertEquals(NotificationPreference::defaults(), $resolution->resolve(null, null));
    }

    public function testRecipientScopeAllowsAdministratorAndOnlyAssignedRestrictedBranches(): void
    {
        $policy = new NotificationRecipientScopePolicy();
        self::assertTrue($policy->allows(5, 9, 5, true, []));
        self::assertTrue($policy->allows(5, 9, 5, false, [9]));
        self::assertFalse($policy->allows(5, 9, 5, false, [8]));
        self::assertFalse($policy->allows(5, 9, 6, true, []));
    }

    public function testTenantWithoutNotificationPermissionIsRejected(): void
    {
        $this->expectException(DomainException::class);
        (new GetNotificationCenter(new FakeNotificationRepository()))->execute(new ActorContext(10, 5, false, true, ['Administrador'], [], []));
    }

    public function testWebPushTestTargetsOnlyAuthenticatedActor(): void
    {
        $gateway = new RecordingPushGateway();
        $result = (new SendWebPushTest($gateway))->execute(new ActorContext(77, 5, false, true, ['Administrador'], ['notificaciones.ver'], []));
        self::assertSame(77, $gateway->userId);
        self::assertSame(1, $result['sent']);
    }

    public function testMigrationDeclaresCompositeTenantForeignKeys(): void
    {
        $source = file_get_contents(APPPATH . 'Database/Migrations/2026-08-12-120300_CreateNotificationsCore.php');
        self::assertIsString($source);
        self::assertStringContainsString("['empresa_id', 'sucursal_id'], 'sucursales', ['empresa_id', 'id']", $source);
        self::assertStringContainsString("['empresa_id', 'usuario_id'], 'usuarios', ['empresa_id', 'id']", $source);
        self::assertStringContainsString('uq_usuarios_empresa_id_id', $source);
        $resolver = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterNotificationRecipientResolver.php');
        self::assertStringContainsString("p_n.clave = 'notificaciones.ver'", $resolver);
        $seeder = file_get_contents(APPPATH . 'Database/Seeds/NotificationDefaultsSeeder.php');
        self::assertStringContainsString("where('rol_id', \$role['id'])->where('tipo_evento', \$event)", $seeder);
        self::assertStringContainsString("'orden.asignada'", $seeder);
    }

    private function event(): NotifiableEvent
    {
        return new NotifiableEvent(5, 7, 'preventivo.vencido', NotificationSeverity::CRITICAL, 'Plan vencido', 'Requiere atención', 'plan', '44', 'preventivo_vencido:plan:44:ciclo:100000', '/mantenimiento/planes/44', new DateTimeImmutable('2026-08-12 07:00:00'));
    }
}

final class FakeNotificationRepository implements NotificationRepository
{
    /** @var array<string,int> */ public array $keys = [];
    /** @var array{int,int,list<int>|null,int,int}|null */ public ?array $lastScope = null;
    public function createIfAbsent(Notification $notification): ?int { $key = $notification->recipientUserId() . ':' . $notification->idempotencyKey(); if (isset($this->keys[$key])) return null; return $this->keys[$key] = count($this->keys) + 1; }
    public function listForUser(int $companyId, int $userId, ?array $branchIds, int $page, int $perPage): NotificationCenterPage { $this->lastScope = [$companyId, $userId, $branchIds, $page, $perPage]; return new NotificationCenterPage([], 0, $page, $perPage, 0); }
    public function markRead(int $companyId, int $userId, ?array $branchIds, int $notificationId, DateTimeImmutable $at): bool { return true; }
    public function markAllRead(int $companyId, int $userId, ?array $branchIds, DateTimeImmutable $at): int { return 0; }
}
final readonly class FakeNotificationRecipientResolver implements NotificationRecipientResolver { public function __construct(private array $recipients) {} public function resolve(NotifiableEvent $event): array { return $this->recipients; } }
final class FakeNotificationPreferenceStore implements NotificationPreferenceStore
{
    public function resolve(int $userId, string $eventType): NotificationPreference { return NotificationPreference::defaults(); }
    public function save(int $userId, string $eventType, NotificationPreference $preference): void {}
    public function allForUser(int $userId): array { return []; }
}
final class FakeNotificationDeliveryQueue implements NotificationDeliveryQueue
{
    public array $scheduled = []; public array $dueByChannel = []; public array $delivered = []; public array $failed = []; public array $skipped = [];
    public function schedule(int $notificationId, int $userId, string $eventKey, NotificationSeverity $severity, NotificationPreference $preference): void { $this->scheduled[] = ['notification' => $notificationId, 'user' => $userId]; }
    public function due(string $channel, int $limit): array { return $this->dueByChannel[$channel] ?? []; }
    public function delivered(int $deliveryId): void { $this->delivered[] = $deliveryId; }
    public function skipped(int $deliveryId, string $reason): void { $this->skipped[] = $deliveryId; }
    public function failed(int $deliveryId, string $error, bool $retryable): void { $this->failed[] = [$deliveryId, $retryable]; }
}
final class FakeWebPushSubscriptionStore implements WebPushSubscriptionStore
{
    /** @var list<WebPushSubscription> */ public array $subscriptions = [];
    public function upsert(WebPushSubscription $subscription): int { $this->subscriptions[] = $subscription; return count($this->subscriptions); }
    public function deactivate(int $userId, string $endpoint): bool { return false; }
    public function activeForUser(int $userId): array { return []; }
    public function markDelivered(string $endpoint): void {}
    public function markFailed(string $endpoint, string $error): void {}
    public function markInvalid(string $endpoint, string $error): void {}
}
final class FakeNotificationProcessControl implements NotificationProcessControl
{
    public bool $released = false; public ?int $startId = 1;
    public function acquire(string $process, int $ttlSeconds): ?string { return 'token'; }
    public function start(string $process, string $executionKey): ?int { return $this->startId; }
    public function finish(int $executionId, array $summary): void {}
    public function fail(int $executionId, string $error): void {}
    public function release(string $process, string $token): void { $this->released = true; }
}
final class FailingEmailGateway implements EmailNotificationGateway { public function sendDigest(string $recipient, array $notifications): void { throw new RuntimeException('SMTP temporal'); } }
final class NoopEmailGateway implements EmailNotificationGateway { public function sendDigest(string $recipient, array $notifications): void {} }
final class SuccessfulPushGateway implements WebPushGateway { public function sendToUser(int $userId, string $title, string $summary, ?string $url): array { return ['sent' => 1, 'expired' => 0, 'failed' => 0]; } }
final class EmptyPushGateway implements WebPushGateway { public function sendToUser(int $userId, string $title, string $summary, ?string $url): array { return ['sent' => 0, 'expired' => 0, 'failed' => 0]; } }
final class RecordingPushGateway implements WebPushGateway { public ?int $userId = null; public function sendToUser(int $userId, string $title, string $summary, ?string $url): array { $this->userId = $userId; return ['sent' => 1, 'expired' => 0, 'failed' => 0]; } }
final class FakeNotificationUnitOfWork implements NotificationUnitOfWork { public function transactional(callable $operation): mixed { return $operation(); } }
