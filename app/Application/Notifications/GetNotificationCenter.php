<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\NotificationRepository;
use DomainException;

final readonly class GetNotificationCenter
{
    public function __construct(private NotificationRepository $notifications)
    {
    }

    public function execute(ActorContext $actor, int $page = 1, int $perPage = 10): NotificationCenterPage
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('notificaciones.ver')) {
            throw new DomainException('El centro de notificaciones operativo requiere una empresa.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();

        return $this->notifications->listForUser(
            $actor->companyId(),
            $actor->userId(),
            $branchIds,
            max(1, $page),
            in_array($perPage, [5, 10, 25], true) ? $perPage : 10,
        );
    }
}
