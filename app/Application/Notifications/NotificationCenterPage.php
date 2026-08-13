<?php

declare(strict_types=1);

namespace App\Application\Notifications;

final readonly class NotificationCenterPage
{
    /** @param list<array<string,mixed>> $items */
    public function __construct(public array $items, public int $unread, public int $page, public int $perPage, public int $total)
    {
    }
}
