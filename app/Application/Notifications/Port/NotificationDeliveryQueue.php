<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Domain\Notifications\NotificationPreference;
use App\Domain\Notifications\NotificationSeverity;

interface NotificationDeliveryQueue
{
    public function schedule(int $notificationId, int $userId, string $eventKey, NotificationSeverity $severity, NotificationPreference $preference): void;

    /** @return list<array{id:int,notification_id:int,usuario_id:int,email:string,canal:string,titulo:string,resumen:string,url:?string,severidad:string,intentos:int}> */
    public function due(string $channel, int $limit): array;

    public function delivered(int $deliveryId): void;

    public function skipped(int $deliveryId, string $reason): void;

    public function failed(int $deliveryId, string $error, bool $retryable): void;
}
