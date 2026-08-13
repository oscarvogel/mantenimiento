<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Application\Notifications\NotificationCenterPage;
use App\Domain\Notifications\Notification;
use DateTimeImmutable;

interface NotificationRepository
{
    public function createIfAbsent(Notification $notification): ?int;

    /** @param list<int>|null $branchIds */
    public function listForUser(int $companyId, int $userId, ?array $branchIds, int $page, int $perPage): NotificationCenterPage;

    /** @param list<int>|null $branchIds */
    public function markRead(int $companyId, int $userId, ?array $branchIds, int $notificationId, DateTimeImmutable $at): bool;

    /** @param list<int>|null $branchIds */
    public function markAllRead(int $companyId, int $userId, ?array $branchIds, DateTimeImmutable $at): int;
}
