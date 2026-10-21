<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

use App\Application\Identity\ActorContext;
use DateTimeImmutable;

interface TripReadingRecorder
{
    public function record(ActorContext $actor, int $equipmentId, DateTimeImmutable $at, int $kilometers, string $reference): void;
}
