<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use App\Application\Notifications\Port\WhatsAppNotificationDeliveryQueue;
use App\Domain\Notifications\NotifiableEvent;

/**
 * Doble de la cola de WhatsApp que no toca la base.
 *
 * Reproduce la garantía que importa para este circuito: la clave de entrega es
 * la que impide duplicar el aviso. Si la clave ya existe, `enqueue()` devuelve
 * 0 y no se agrega nada, igual que hace el adaptador real con su chequeo previo.
 */
final class FakeWhatsAppDeliveryQueue implements WhatsAppNotificationDeliveryQueue
{
    /** @var list<array{companyId:int,deliveryKey:string,phone:string,instanceId:string,message:string,scheduledAt:string}> */
    public array $enqueued = [];

    public function enqueueManagementReadingReport(
        int $companyId,
        string $deliveryKey,
        string $phone,
        string $instanceId,
        string $message,
        string $scheduledAt,
    ): int {
        foreach ($this->enqueued as $row) {
            if ($row['deliveryKey'] === $deliveryKey) {
                return 0;
            }
        }

        $this->enqueued[] = [
            'companyId' => $companyId,
            'deliveryKey' => $deliveryKey,
            'phone' => $phone,
            'instanceId' => $instanceId,
            'message' => $message,
            'scheduledAt' => $scheduledAt,
        ];

        return count($this->enqueued);
    }

    public function scheduleDriverForEvent(NotifiableEvent $event): void
    {
    }

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

    public function due(int $limit): array
    {
        return [];
    }

    public function accepted(int $deliveryId, string $messageId, string $status): void
    {
    }

    public function awaitingConfirmation(int $limit): array
    {
        return [];
    }

    public function reconcileStatus(int $deliveryId, string $status, ?string $providerMessageId = null, ?string $error = null): void
    {
    }

    public function skipped(int $deliveryId, string $reason): void
    {
    }

    public function failed(int $deliveryId, string $error, bool $retryable): void
    {
    }
}
