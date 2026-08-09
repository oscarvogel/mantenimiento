<?php

declare(strict_types=1);

namespace App\Infrastructure\WorkOrders;

use App\Application\WorkOrders\Port\Clock;
use DateTimeImmutable;
use DateTimeZone;

final readonly class SystemClock implements Clock
{
    public function __construct(private DateTimeZone $timezone = new DateTimeZone('America/Argentina/Buenos_Aires'))
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }
}
