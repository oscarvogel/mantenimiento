<?php

declare(strict_types=1);

use App\Application\Notifications\CollectOperationalNotifications;
use App\Application\Notifications\NotificationDispatchInProgress;
use App\Application\Notifications\Port\AutomationExecutionRecorder;
use App\Application\Notifications\Port\CompanyNotificationDeliveryQueue;
use App\Application\Notifications\Port\EmailNotificationGateway;
use App\Application\Notifications\Port\NotificationDeliveryQueue;
use App\Application\Notifications\Port\NotificationPreferenceStore;
use App\Application\Notifications\Port\NotificationProcessControl;
use App\Application\Notifications\Port\NotificationRecipientResolver;
use App\Application\Notifications\Port\NotificationRepository;
use App\Application\Notifications\Port\NotificationUnitOfWork;
use App\Application\Notifications\Port\NotifiableEventPublisher;
use App\Application\Notifications\Port\OperationalNotificationEventSource;
use App\Application\Notifications\Port\WebPushGateway;
use App\Application\Notifications\PublishNotifiableEvent;
use App\Application\Notifications\RunNotificationCycle;
use App\Application\Notifications\RunNotificationDispatch;
use App\Application\PreventiveMaintenance\ConsultarVencimientos;
use App\Application\PreventiveMaintenance\DetectOverduePlansAutomatically;
use App\Application\PreventiveMaintenance\MaterializarAvisoVencido;
use App\Application\PreventiveMaintenance\Port\ActiveCompanyCatalog;
use App\Application\PreventiveMaintenance\Port\Clock as PreventiveClock;
use App\Application\PreventiveMaintenance\Port\MaintenanceNoticeRepository;
use App\Application\PreventiveMaintenance\Port\PlanMantenimientoRepository;
use App\Application\PreventiveMaintenance\Port\PreventiveAssetGateway;
use App\Application\Telematic\Port\TelemetryEvaluator;
use App\Application\Telematic\Port\TelemetryIntegrationCatalog;
use App\Application\Telematic\Port\TelemetryRefreshGuard;
use App\Application\Telematic\Port\TelemetryRefresher;
use App\Application\Telematic\RefreshTelemetryNow;
use App\Domain\Notifications\NotificationPreference;
use App\Domain\Notifications\NotificationSeverity;
use App\Domain\Notifications\NotifiableEvent;
use App\Domain\Notifications\Notification;
use PHPUnit\Framework\TestCase;
use Tests\Support\Notifications\FixedNotificationClock;

final class RunNotificationCycleStagesTest extends TestCase
{
    public function testRecordsStagesAndKeepsTheCyclePartialWhenTelemetryFailsAfterDispatch(): void
    {
        $recorder = new CycleStageRecorder();
        $processes = new CycleProcessControl();
        $dispatchQueue = new CycleDeliveryQueue();
        $dispatch = new RunNotificationDispatch(
            $dispatchQueue,
            new CycleEmailGateway(),
            new CyclePushGateway(),
            $processes,
        );

        $cycle = new RunNotificationCycle(
            $this->detectOverdue(),
            $this->collector(),
            $dispatch,
            new FixedNotificationClock(new DateTimeImmutable('2026-11-05 07:00:00')),
            null,
            null,
            null,
            [$this->failingTelemetry()],
            $processes,
            $recorder,
        );

        $result = $cycle->execute('2026-11-05-07-00');

        self::assertSame('parcial', $result['status']);
        self::assertSame('FALLIDA', $this->stageState($recorder->stages, 'notificaciones:ciclo:telemetria'));
        self::assertSame('COMPLETADA', $this->stageState($recorder->stages, 'notificaciones:ciclo:despacho'));
        self::assertSame('COMPLETADA', $this->stageState($recorder->stages, 'notificaciones:ciclo:recoleccion'));
        self::assertSame('OMITIDA', $this->stageState($recorder->stages, 'notificaciones:ciclo:telefonos_choferes'));
        self::assertSame(1, $processes->failed);
        self::assertContains('EMAIL', $dispatchQueue->dueCalls);
        self::assertContains('PUSH', $dispatchQueue->dueCalls);
    }

    public function testRepeatedCompletedCycleKeyDoesNotRunStagesAgain(): void
    {
        $recorder = new CycleStageRecorder();
        $processes = new CycleProcessControl();
        $processes->cycleStartId = null;

        $cycle = new RunNotificationCycle(
            $this->detectOverdue(),
            $this->collector(),
            new RunNotificationDispatch(new CycleDeliveryQueue(), new CycleEmailGateway(), new CyclePushGateway(), $processes),
            new FixedNotificationClock(new DateTimeImmutable('2026-11-05 07:00:00')),
            null,
            null,
            null,
            [],
            $processes,
            $recorder,
        );

        $result = $cycle->execute('2026-11-05-07-00');

        self::assertSame('completada', $result['status']);
        self::assertSame([], $recorder->stages);
        self::assertSame(1, $processes->started);
    }

    public function testConcurrentCycleIsRejectedBeforeAnyStage(): void
    {
        $processes = new CycleProcessControl();
        $processes->acquireResult = null;

        $cycle = new RunNotificationCycle(
            $this->detectOverdue(),
            $this->collector(),
            new RunNotificationDispatch(new CycleDeliveryQueue(), new CycleEmailGateway(), new CyclePushGateway(), $processes),
            new FixedNotificationClock(new DateTimeImmutable('2026-11-05 07:00:00')),
            null,
            null,
            null,
            [],
            $processes,
            new CycleStageRecorder(),
        );

        $this->expectException(NotificationDispatchInProgress::class);
        $cycle->execute('2026-11-05-07-00');
    }

    private function detectOverdue(): DetectOverduePlansAutomatically
    {
        $clock = new class implements PreventiveClock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-11-05 07:00:00');
            }
        };

        return new DetectOverduePlansAutomatically(
            new class implements ActiveCompanyCatalog {
                public function listActiveCompanyIds(): array
                {
                    return [];
                }
            },
            new ConsultarVencimientos(
                new CyclePlanRepository(),
                new CycleAssetGateway(),
                $clock,
                new App\Domain\PreventiveMaintenance\EvaluadorVencimiento(),
            ),
            new MaterializarAvisoVencido(new CycleNoticeRepository()),
            $clock,
        );
    }

    private function collector(): CollectOperationalNotifications
    {
        return new CollectOperationalNotifications(
            new class implements OperationalNotificationEventSource {
                public function collect(): array
                {
                    return [];
                }
            },
            new PublishNotifiableEvent(
                new CycleRecipientResolver(),
                new CycleNotificationRepository(),
                new CyclePreferenceStore(),
                new CycleDeliveryQueue(),
                new CycleUnitOfWork(),
            ),
        );
    }

    private function failingTelemetry(): RefreshTelemetryNow
    {
        return new RefreshTelemetryNow(
            new class implements TelemetryIntegrationCatalog {
                public function activeFor(int $companyId): array
                {
                    return [];
                }
            },
            new class implements TelemetryRefresher {
                public function execute(): array
                {
                    return [];
                }
            },
            new class implements TelemetryEvaluator {
                public function execute(): array
                {
                    return [];
                }
            },
            new class implements NotifiableEventPublisher {
                public function publish(NotifiableEvent $event): void
                {
                }

                public function execute(NotifiableEvent $event): array
                {
                    return ['created' => 0, 'duplicates' => 0];
                }
            },
            new class implements TelemetryRefreshGuard {
                public function ultimaLecturaDe(int $integrationId): ?DateTimeImmutable
                {
                    return null;
                }
            },
            new FixedNotificationClock(new DateTimeImmutable('2026-11-05 07:00:00')),
            5,
        );
    }

    /** @param list<array{state:string}> $stages */
    private function stageState(array $stages, string $process): string
    {
        foreach ($stages as $stage) {
            if ($stage['process'] === $process) {
                return $stage['state'];
            }
        }

        self::fail('Falta la etapa ' . $process);
    }
}

final class CycleStageRecorder implements AutomationExecutionRecorder
{
    /** @var list<array{process:string,state:string,companyId:?int}> */
    public array $stages = [];

    private int $next = 1;

    public function begin(string $process, string $executionKey, ?int $companyId): int
    {
        $id = $this->next++;
        $this->stages[$id] = ['process' => $process, 'state' => 'EN_PROCESO', 'companyId' => $companyId];

        return $id;
    }

    public function finish(int $id, array $summary): void
    {
        $this->stages[$id]['state'] = 'COMPLETADA';
    }

    public function fail(int $id, string $safeError): void
    {
        $this->stages[$id]['state'] = 'FALLIDA';
        $this->stages[$id]['error'] = $safeError;
    }

    public function skip(int $id, string $safeReason): void
    {
        $this->stages[$id]['state'] = 'OMITIDA';
    }
}

final class CycleProcessControl implements NotificationProcessControl
{
    public ?string $acquireResult = 'token';
    public ?int $cycleStartId = 1;
    public int $started = 0;
    public int $failed = 0;
    public int $finished = 0;
    private int $nextId = 1;

    public function acquire(string $process, int $ttlSeconds): ?string
    {
        return $process === 'notificaciones:ciclo' ? $this->acquireResult : 'token';
    }

    public function start(string $process, string $executionKey): ?int
    {
        $this->started++;

        return $process === 'notificaciones:ciclo' ? $this->cycleStartId : $this->nextId++;
    }

    public function finish(int $executionId, array $summary): void
    {
        $this->finished++;
    }

    public function fail(int $executionId, string $error): void
    {
        $this->failed++;
    }

    public function release(string $process, string $token): void
    {
    }
}

final class CycleDeliveryQueue implements NotificationDeliveryQueue
{
    public array $dueCalls = [];

    public function schedule(int $notificationId, int $userId, string $eventKey, NotificationSeverity $severity, NotificationPreference $preference): void
    {
    }

    public function due(string $channel, int $limit): array
    {
        $this->dueCalls[] = $channel;

        return [];
    }

    public function delivered(int $deliveryId): void
    {
    }

    public function skipped(int $deliveryId, string $reason): void
    {
    }

    public function failed(int $deliveryId, string $error, bool $retryable): void
    {
    }
}

final class CycleEmailGateway implements EmailNotificationGateway
{
    public function sendDigest(string $recipient, array $notifications): void
    {
    }
}

final class CyclePushGateway implements WebPushGateway
{
    public function sendToUser(int $userId, string $title, string $summary, ?string $url): array
    {
        return ['sent' => 0, 'expired' => 0, 'failed' => 0];
    }
}

final class CyclePlanRepository implements PlanMantenimientoRepository
{
    public function findScoped(int $companyId, int $planId, ?array $branchIds, bool $forUpdate = false): ?App\Domain\PreventiveMaintenance\PlanMantenimiento
    {
        return null;
    }

    public function existsActive(int $companyId, int $equipmentId, int $serviceTypeId, ?array $branchIds): bool
    {
        return false;
    }

    public function listActiveScoped(int $companyId, ?array $branchIds): array
    {
        return [];
    }

    public function save(App\Domain\PreventiveMaintenance\PlanMantenimiento $plan, int $actorUserId): int
    {
        return 0;
    }
}

final class CycleAssetGateway implements PreventiveAssetGateway
{
    public function findScoped(int $companyId, int $equipmentId, ?array $branchIds): ?App\Application\PreventiveMaintenance\EquipmentForPlan
    {
        return null;
    }
}

final class CycleNoticeRepository implements MaintenanceNoticeRepository
{
    public function findByCycleKey(int $companyId, int $planId, string $cycleKey): ?App\Domain\PreventiveMaintenance\AvisoPlan
    {
        return null;
    }

    public function findScoped(int $companyId, int $noticeId, ?array $branchIds, bool $forUpdate = false): ?App\Domain\PreventiveMaintenance\AvisoPlan
    {
        return null;
    }

    public function pendingForCycle(int $companyId, int $planId, string $claveCiclo): array
    {
        return [];
    }

    public function save(App\Domain\PreventiveMaintenance\AvisoPlan $notice, ?int $actorUserId): int
    {
        return 0;
    }
}

final class CycleRecipientResolver implements NotificationRecipientResolver
{
    public function resolve(NotifiableEvent $event): array
    {
        return [];
    }
}

final class CycleNotificationRepository implements NotificationRepository
{
    public function createIfAbsent(Notification $notification): ?int
    {
        return null;
    }

    public function refresh(int $companyId, int $userId, string $eventKey, string $title, string $summary, ?string $url, DateTimeImmutable $at): App\Application\Notifications\NotificationRefreshOutcome
    {
        return App\Application\Notifications\NotificationRefreshOutcome::MISSING;
    }

    public function regularizePending(int $companyId, string $eventType, DateTimeImmutable $at, ?string $exceptEventKey = null): int
    {
        return 0;
    }

    public function companiesWithPending(string $eventType): array
    {
        return [];
    }

    public function listForUser(int $companyId, int $userId, ?array $branchIds, int $page, int $perPage): App\Application\Notifications\NotificationCenterPage
    {
        return new App\Application\Notifications\NotificationCenterPage([], 0, 1, $perPage, 0);
    }

    public function markRead(int $companyId, int $userId, ?array $branchIds, int $notificationId, DateTimeImmutable $at): bool
    {
        return false;
    }

    public function markAllRead(int $companyId, int $userId, ?array $branchIds, DateTimeImmutable $at): int
    {
        return 0;
    }
}

final class CyclePreferenceStore implements NotificationPreferenceStore
{
    public function resolve(int $userId, string $eventType): NotificationPreference
    {
        return NotificationPreference::defaults();
    }

    public function save(int $userId, string $eventType, NotificationPreference $preference): void
    {
    }

    public function allForUser(int $userId): array
    {
        return [];
    }
}

final class CycleUnitOfWork implements NotificationUnitOfWork
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
