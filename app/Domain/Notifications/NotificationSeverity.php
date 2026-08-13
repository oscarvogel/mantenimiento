<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

enum NotificationSeverity: string
{
    case INFO = 'INFO';
    case WARNING = 'ADVERTENCIA';
    case CRITICAL = 'CRITICA';
}
