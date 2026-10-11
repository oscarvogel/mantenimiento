<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use App\Application\Notifications\Port\NotificationClock;
use DateTimeImmutable;

/**
 * Doble del reloj de notificaciones con hora fija.
 *
 * La auditoría de celulares se decide contra el reloj, no contra el servidor:
 * el mismo reloj en dos corridas consecutivas debe producir el mismo resultado.
 */
final class FixedNotificationClock implements NotificationClock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}