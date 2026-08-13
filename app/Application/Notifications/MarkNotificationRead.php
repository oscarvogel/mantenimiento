<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\NotificationRepository;
use DomainException;

final readonly class MarkNotificationRead
{
    public function __construct(private NotificationRepository $notifications, private NotificationClock $clock)
    {
    }

    public function one(ActorContext $actor, int $notificationId): void
    {
        [$companyId, $branchIds] = $this->scope($actor);
        if (! $this->notifications->markRead($companyId, $actor->userId(), $branchIds, $notificationId, $this->clock->now())) {
            throw new DomainException('La notificación no existe o no está autorizada.');
        }
    }

    public function all(ActorContext $actor): int
    {
        [$companyId, $branchIds] = $this->scope($actor);

        return $this->notifications->markAllRead($companyId, $actor->userId(), $branchIds, $this->clock->now());
    }

    /** @return array{int,list<int>|null} */
    private function scope(ActorContext $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('notificaciones.ver')) {
            throw new DomainException('La operación requiere una empresa.');
        }

        return [$actor->companyId(), $actor->hasAllCompanyBranches() ? null : $actor->branchIds()];
    }
}
