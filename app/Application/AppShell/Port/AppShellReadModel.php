<?php

declare(strict_types=1);

namespace App\Application\AppShell\Port;

use App\Application\Identity\ActorContext;

interface AppShellReadModel
{
    /** @return array{user:array<string,mixed>,company:array<string,mixed>|null,branches:list<array<string,mixed>>} */
    public function fetch(ActorContext $actor): array;
}
