<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement;

use App\Application\Measurement\Port\Clock;
use DateTimeImmutable;

final readonly class SystemClock implements Clock
{
    public function now(): DateTimeImmutable { return new DateTimeImmutable(); }
}
