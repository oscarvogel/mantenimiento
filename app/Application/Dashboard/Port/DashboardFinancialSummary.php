<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Port;

use App\Application\Identity\ActorContext;
use DateTimeImmutable;

interface DashboardFinancialSummary
{
    /** @return array<string,mixed> */
    public function fetch(ActorContext $actor, DateTimeImmutable $today): array;
}
