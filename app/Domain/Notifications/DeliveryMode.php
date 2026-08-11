<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

enum DeliveryMode: string
{
    case DISABLED = 'DESACTIVADO';
    case IMMEDIATE = 'INMEDIATO';
    case DAILY_DIGEST = 'RESUMEN';
    case CRITICAL_ONLY = 'CRITICO';

    public function accepts(NotificationSeverity $severity, bool $digest): bool
    {
        return match ($this) {
            self::DISABLED => false,
            self::IMMEDIATE => ! $digest,
            self::DAILY_DIGEST => $digest,
            self::CRITICAL_ONLY => $severity === NotificationSeverity::CRITICAL && ! $digest,
        };
    }
}
