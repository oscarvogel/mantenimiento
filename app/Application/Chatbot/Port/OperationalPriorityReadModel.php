<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Port;

use App\Application\Identity\ActorContext;

interface OperationalPriorityReadModel
{
    /** @return array<string,mixed> */
    public function analyze(ActorContext $actor, int $limit = 5): array;
}
